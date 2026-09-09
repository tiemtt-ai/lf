<?php

namespace App\Services;

use App\Exceptions\AiKnowledgeIngestionException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AiKnowledgeIngestionService
{
    public const CHUNKER_VERSION = 'media-unit-unicode-v1';

    public const MAX_CHARS = 4000;

    public function __construct(private readonly MediaReadService $mediaRead) {}

    /**
     * Register and deterministically chunk one authorized Media Read revision.
     *
     * @return array{source_id:int,source_uuid:string,generation:int,chunk_count:int,reused:bool}
     */
    public function ingestMedia(
        int $actorId,
        string $ownerType,
        int $ownerId,
        string $usageType,
        string $contentType,
        ?string $locale,
        string $title,
        string|array|null $languageProfile = null,
    ): array {
        $customerId = TenantContext::customerId()
            ?? throw new AiKnowledgeIngestionException('unauthorized');

        $units = $this->mediaRead->read(
            $actorId,
            $ownerType,
            $ownerId,
            $usageType,
            $contentType,
            $locale,
            null,
            null,
            'ai_knowledge_ingestion',
            ['operation' => 'knowledge_ingestion'],
            languageProfile: $languageProfile,
        );

        if ($units === []) {
            throw new AiKnowledgeIngestionException('empty_revision');
        }

        $revision = $this->validatedRevision($units, $contentType);
        $profile = $this->orderedProfile($revision['language_profile'] ?? $languageProfile);
        $desiredChunks = $this->desiredChunks($units, $revision, $profile);

        return DB::transaction(function () use (
            $customerId, $actorId, $ownerType, $ownerId, $usageType, $contentType,
            $title, $revision, $profile, $desiredChunks
        ): array {
            $logical = DB::table('ai_knowledge_sources')
                ->where('customer_id', $customerId)
                ->where('source_type', $ownerType)
                ->where('source_id', $ownerId)
                ->where('usage_type', $usageType)
                ->where('content_type', $contentType)
                ->where('locale', $revision['locale'])
                ->lockForUpdate()
                ->get();

            $sameRevision = $logical->filter(fn (object $source): bool => rtrim((string) $source->source_fingerprint) === $revision['source_fingerprint']
                && $source->processing_version === $revision['processing_version']
            );
            if ($sameRevision->contains(fn (object $source): bool => $source->status === 'deletion_pending')) {
                throw new AiKnowledgeIngestionException('source_deletion_pending');
            }
            $exact = $sameRevision->first(fn (object $source): bool => in_array($source->status, ['pending', 'active', 'failed'], true)
            );

            $reused = $exact !== null;
            if ($exact === null) {
                $generation = max(1, ((int) $sameRevision->max('generation')) + 1);
                $sourceUuid = $this->stableUuid(implode('|', [
                    'source', $customerId, $ownerType, $ownerId, $usageType, $contentType,
                    $revision['locale'] ?? '', $revision['source_fingerprint'],
                    $revision['processing_version'], $generation,
                ]));

                try {
                    $sourceId = DB::table('ai_knowledge_sources')->insertGetId([
                        'customer_id' => $customerId,
                        'source_uuid' => $sourceUuid,
                        'source_type' => $ownerType,
                        'source_id' => $ownerId,
                        'media_file_id' => $revision['media_file_id'],
                        'usage_type' => $usageType,
                        'generation' => $generation,
                        'content_type' => $contentType,
                        'title' => $title,
                        'locale' => $revision['locale'],
                        'source_fingerprint' => $revision['source_fingerprint'],
                        'processing_version' => $revision['processing_version'],
                        'status' => 'pending',
                        'created_by' => $actorId,
                        'metadata' => $this->json([
                            'language_profile' => $profile,
                            'chunker_version' => self::CHUNKER_VERSION,
                            'chunk_max_chars' => self::MAX_CHARS,
                            'owner_context' => ['type' => $ownerType, 'id' => $ownerId, 'usage_type' => $usageType],
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (QueryException $exception) {
                    // A concurrent retry can win after our gap read. Resolve
                    // only the exact deterministic UUID; never turn another
                    // integrity error into a successful registration.
                    $concurrent = DB::table('ai_knowledge_sources')
                        ->where('customer_id', $customerId)->where('source_uuid', $sourceUuid)->first();
                    if ($concurrent === null) {
                        throw new AiKnowledgeIngestionException('registration_conflict');
                    }
                    $storedProfile = $this->orderedProfile(
                        json_decode((string) $concurrent->metadata, true)['language_profile'] ?? null
                    );
                    if ($storedProfile !== $profile || (int) $concurrent->media_file_id !== $revision['media_file_id']) {
                        throw new AiKnowledgeIngestionException('revision_identity_conflict');
                    }
                    $sourceId = (int) $concurrent->id;
                    $sourceUuid = $concurrent->source_uuid;
                    $generation = (int) $concurrent->generation;
                    $reused = true;
                }
            } else {
                $sourceId = (int) $exact->id;
                $sourceUuid = $exact->source_uuid;
                $generation = (int) $exact->generation;
                $storedProfile = $this->orderedProfile(
                    json_decode((string) $exact->metadata, true)['language_profile'] ?? null
                );
                if ($storedProfile !== $profile || (int) $exact->media_file_id !== $revision['media_file_id']) {
                    throw new AiKnowledgeIngestionException('revision_identity_conflict');
                }
            }

            $this->reconcileChunks($customerId, $sourceId, $sourceUuid, $desiredChunks);

            $now = now();
            $oldIds = $logical->filter(fn (object $source): bool => (int) $source->id !== $sourceId
                && in_array($source->status, ['pending', 'active', 'failed'], true)
            )->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($oldIds !== []) {
                $oldChunkIds = DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)
                    ->whereIn('knowledge_source_id', $oldIds)->pluck('id');
                if ($oldChunkIds->isNotEmpty()) {
                    DB::table('ai_embeddings')->where('customer_id', $customerId)
                        ->whereIn('knowledge_chunk_id', $oldChunkIds)
                        ->whereIn('status', ['pending', 'ready', 'failed'])
                        ->update(['status' => 'stale', 'updated_at' => $now]);
                }
                DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)
                    ->whereIn('knowledge_source_id', $oldIds)
                    ->whereIn('status', ['pending', 'active', 'failed'])
                    ->update(['status' => 'stale', 'updated_at' => $now]);
                DB::table('ai_knowledge_sources')->where('customer_id', $customerId)
                    ->whereIn('id', $oldIds)
                    ->update(['status' => 'stale', 'updated_at' => $now]);
            }

            DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->where('id', $sourceId)
                ->update(['status' => 'active', 'last_synced_at' => $now, 'updated_at' => $now]);

            return [
                'source_id' => $sourceId,
                'source_uuid' => $sourceUuid,
                'generation' => $generation,
                'chunk_count' => count($desiredChunks),
                'reused' => $reused,
            ];
        }, 3);
    }

    public function requestSourceDeletion(int $sourceId): void
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiKnowledgeIngestionException('unauthorized');

        DB::transaction(function () use ($customerId, $sourceId): void {
            $source = DB::table('ai_knowledge_sources')->where('customer_id', $customerId)
                ->where('id', $sourceId)->lockForUpdate()->first();
            if ($source === null) {
                throw new AiKnowledgeIngestionException('source_not_found');
            }
            if ($source->status === 'deleted') {
                return;
            }

            $now = now();
            $chunkIds = DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)
                ->where('knowledge_source_id', $sourceId)->pluck('id');
            if ($chunkIds->isNotEmpty()) {
                DB::table('ai_embeddings')->where('customer_id', $customerId)
                    ->whereIn('knowledge_chunk_id', $chunkIds)->where('status', '<>', 'deleted')
                    ->update(['status' => 'deletion_pending', 'deletion_requested_at' => $now, 'updated_at' => $now]);
            }
            DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)
                ->where('knowledge_source_id', $sourceId)->where('status', '<>', 'deleted')
                ->update(['status' => 'deletion_pending', 'deletion_requested_at' => $now, 'updated_at' => $now]);
            DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->where('id', $sourceId)
                ->update(['status' => 'deletion_pending', 'deletion_requested_at' => $now, 'updated_at' => $now]);
        }, 3);
    }

    public function finalizeSourceDeletion(int $sourceId): void
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiKnowledgeIngestionException('unauthorized');

        DB::transaction(function () use ($customerId, $sourceId): void {
            $source = DB::table('ai_knowledge_sources')->where('customer_id', $customerId)
                ->where('id', $sourceId)->lockForUpdate()->first();
            if ($source === null) {
                throw new AiKnowledgeIngestionException('source_not_found');
            }
            if ($source->status === 'deleted') {
                return;
            }
            if ($source->status !== 'deletion_pending') {
                throw new AiKnowledgeIngestionException('deletion_not_requested');
            }

            $chunkIds = DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)
                ->where('knowledge_source_id', $sourceId)->lockForUpdate()->pluck('id');
            $blockingEmbeddingId = $chunkIds->isEmpty() ? null : DB::table('ai_embeddings')
                ->where('customer_id', $customerId)->whereIn('knowledge_chunk_id', $chunkIds)
                ->where('status', '<>', 'deleted')->lockForUpdate()->value('id');
            if ($blockingEmbeddingId !== null) {
                throw new AiKnowledgeIngestionException('embedding_delete_barrier');
            }

            $now = now();
            DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)
                ->where('knowledge_source_id', $sourceId)->where('status', '<>', 'deleted')
                ->update(['status' => 'deleted', 'content' => null, 'deleted_at' => $now, 'updated_at' => $now]);
            DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->where('id', $sourceId)
                ->update(['status' => 'deleted', 'deleted_at' => $now, 'updated_at' => $now]);
        }, 3);
    }

    /** @param array<int,array<string,mixed>> $units @return array<string,mixed> */
    private function validatedRevision(array $units, string $contentType): array
    {
        $first = $units[0];
        foreach (['media_file_id', 'source_fingerprint', 'processing_version', 'content_type'] as $key) {
            if (! isset($first[$key]) || $first[$key] === '') {
                throw new AiKnowledgeIngestionException('incomplete_revision');
            }
        }
        if ($first['content_type'] !== $contentType || strlen((string) $first['source_fingerprint']) !== 64) {
            throw new AiKnowledgeIngestionException('invalid_revision');
        }
        foreach ($units as $unit) {
            foreach (['media_file_id', 'source_fingerprint', 'processing_version', 'content_type', 'locale'] as $key) {
                if (($unit[$key] ?? null) !== ($first[$key] ?? null)) {
                    throw new AiKnowledgeIngestionException('mixed_revision');
                }
            }
            if (! is_array($unit['locator'] ?? null)
                || ! in_array($unit['locator']['type'] ?? null, ['page', 'timespan', 'sheet', 'region'], true)
                || ! is_string($unit['locator']['value'] ?? null)) {
                throw new AiKnowledgeIngestionException('invalid_locator');
            }
        }

        return $first;
    }

    /** @param array<int,array<string,mixed>> $units @return array<int,array<string,mixed>> */
    private function desiredChunks(array $units, array $revision, array $profile): array
    {
        $chunks = [];
        $sequence = 1;
        foreach ($units as $unit) {
            $text = $this->unitText($unit, $revision['content_type']);
            if ($text === '') {
                continue;
            }
            foreach ($this->split($text) as $part) {
                $structure = is_array($unit['structure'] ?? null) ? $unit['structure'] : [];
                $bbox = is_array($structure['bbox'] ?? null) ? $structure['bbox'] : [];
                $languages = $structure['languages'] ?? [];
                $chunks[] = [
                    'sequence_no' => $sequence++,
                    'part_index' => $part['index'],
                    'char_start' => $part['start'],
                    'char_end' => $part['end'],
                    'content' => $part['text'],
                    'content_hash' => 'sha256:'.hash('sha256', $part['text']),
                    'token_count' => max(1, (int) ceil(mb_strlen($part['text']) / 4)),
                    'locale' => $unit['locale'] ?? null,
                    'locator_type' => $unit['locator']['type'],
                    'locator_start' => $unit['locator']['value'],
                    'locator_end' => $unit['locator']['value'],
                    'source_role' => in_array($revision['content_type'], ['region', 'formula'], true)
                        ? ($structure['role'] ?? ($revision['content_type'] === 'formula' ? 'other' : null))
                        : null,
                    'source_quality_status' => $revision['content_type'] === 'table'
                        ? ($structure['quality_status'] ?? null)
                        : null,
                    'language_evidence' => $languages === [] ? null : $languages,
                    'reading_order' => $structure['reading_order'] ?? null,
                    'source_text_quality' => $structure['text_quality'] ?? null,
                    'bbox_x' => $bbox['x'] ?? null,
                    'bbox_y' => $bbox['y'] ?? null,
                    'bbox_width' => $bbox['width'] ?? null,
                    'bbox_height' => $bbox['height'] ?? null,
                    'frame_width' => $structure['frame_width'] ?? null,
                    'frame_height' => $structure['frame_height'] ?? null,
                    'metadata' => [
                        'chunker_version' => self::CHUNKER_VERSION,
                        'chunk_max_chars' => self::MAX_CHARS,
                        'language_profile' => $profile,
                    ],
                ];
            }
        }

        return $chunks;
    }

    /** @param array<string,mixed> $unit */
    private function unitText(array $unit, string $contentType): string
    {
        if (is_string($unit['text'] ?? null) && $unit['text'] !== '') {
            return $unit['text'];
        }
        if ($contentType !== 'table' || ! is_array($unit['structure']['cells'] ?? null)) {
            return '';
        }

        $rows = [];
        foreach ($unit['structure']['cells'] as $cell) {
            if (! is_array($cell) || ! isset($cell['row'], $cell['column'])) {
                throw new AiKnowledgeIngestionException('invalid_table_provenance');
            }
            $rows[(int) $cell['row']][(int) $cell['column']] = is_string($cell['text'] ?? null)
                ? $cell['text']
                : '';
        }
        ksort($rows);

        return implode("\n", array_map(function (array $columns): string {
            ksort($columns);

            return implode("\t", $columns);
        }, $rows));
    }

    /** @param array<int,array<string,mixed>> $desired */
    private function reconcileChunks(int $customerId, int $sourceId, string $sourceUuid, array $desired): void
    {
        $existing = DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)
            ->where('knowledge_source_id', $sourceId)->lockForUpdate()->get();
        $seen = [];
        foreach ($desired as $chunk) {
            $key = $chunk['locator_type'].'|'.$chunk['locator_start'].'|'.$chunk['part_index'];
            $chunkUuid = $this->stableUuid(implode('|', [
                'chunk', $sourceUuid, $key, $chunk['char_start'], $chunk['char_end'], self::CHUNKER_VERSION,
            ]));
            $row = $existing->first(fn (object $candidate): bool => $candidate->locator_type === $chunk['locator_type']
                && $candidate->locator_start === $chunk['locator_start']
                && (int) $candidate->part_index === $chunk['part_index']
            );
            $seen[] = $row?->id;
            if ($row !== null) {
                $this->assertChunkSnapshotMatches($row, $chunk, $chunkUuid);
                DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)->where('id', $row->id)
                    ->update(['status' => 'active', 'updated_at' => now()]);

                continue;
            }

            DB::table('ai_knowledge_chunks')->insert([
                'customer_id' => $customerId,
                'knowledge_source_id' => $sourceId,
                'chunk_uuid' => $chunkUuid,
                'status' => 'pending',
                'metadata' => $this->json($chunk['metadata']),
                'language_evidence' => $chunk['language_evidence'] === null ? null : $this->json($chunk['language_evidence']),
                'created_at' => now(),
                'updated_at' => now(),
            ] + array_diff_key($chunk, array_flip(['metadata', 'language_evidence'])));
        }

        $staleIds = $existing->pluck('id')->diff(array_filter($seen))->all();
        if ($staleIds !== []) {
            DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)->whereIn('id', $staleIds)
                ->whereIn('status', ['pending', 'active', 'failed'])
                ->update(['status' => 'stale', 'updated_at' => now()]);
        }
        DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)
            ->where('knowledge_source_id', $sourceId)->where('status', 'pending')
            ->update(['status' => 'active', 'updated_at' => now()]);
    }

    /** @param array<string,mixed> $expected */
    private function assertChunkSnapshotMatches(object $actual, array $expected, string $chunkUuid): void
    {
        $actualSnapshot = [
            'chunk_uuid' => $actual->chunk_uuid,
            'sequence_no' => (int) $actual->sequence_no,
            'content_hash' => $actual->content_hash,
            'token_count' => $actual->token_count === null ? null : (int) $actual->token_count,
            'locale' => $actual->locale,
            'char_start' => (int) $actual->char_start,
            'char_end' => (int) $actual->char_end,
            'locator_end' => $actual->locator_end,
            'source_role' => $actual->source_role,
            'source_quality_status' => $actual->source_quality_status,
            'language_evidence' => $actual->language_evidence === null
                ? null
                : json_decode($actual->language_evidence, true),
            'reading_order' => $actual->reading_order === null ? null : (int) $actual->reading_order,
            'source_text_quality' => $actual->source_text_quality,
            'bbox_x' => $this->normalizedDecimal($actual->bbox_x),
            'bbox_y' => $this->normalizedDecimal($actual->bbox_y),
            'bbox_width' => $this->normalizedDecimal($actual->bbox_width),
            'bbox_height' => $this->normalizedDecimal($actual->bbox_height),
            'frame_width' => $actual->frame_width === null ? null : (int) $actual->frame_width,
            'frame_height' => $actual->frame_height === null ? null : (int) $actual->frame_height,
            'metadata' => json_decode((string) $actual->metadata, true),
        ];
        $expectedSnapshot = [
            'chunk_uuid' => $chunkUuid,
            'sequence_no' => $expected['sequence_no'],
            'content_hash' => $expected['content_hash'],
            'token_count' => $expected['token_count'],
            'locale' => $expected['locale'],
            'char_start' => $expected['char_start'],
            'char_end' => $expected['char_end'],
            'locator_end' => $expected['locator_end'],
            'source_role' => $expected['source_role'],
            'source_quality_status' => $expected['source_quality_status'],
            'language_evidence' => $expected['language_evidence'],
            'reading_order' => $expected['reading_order'],
            'source_text_quality' => $expected['source_text_quality'],
            'bbox_x' => $this->normalizedDecimal($expected['bbox_x']),
            'bbox_y' => $this->normalizedDecimal($expected['bbox_y']),
            'bbox_width' => $this->normalizedDecimal($expected['bbox_width']),
            'bbox_height' => $this->normalizedDecimal($expected['bbox_height']),
            'frame_width' => $expected['frame_width'],
            'frame_height' => $expected['frame_height'],
            'metadata' => $expected['metadata'],
        ];

        if ($actualSnapshot !== $expectedSnapshot) {
            throw new AiKnowledgeIngestionException('non_deterministic_rebuild');
        }
    }

    private function normalizedDecimal(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 6);
    }

    /** @return array<int,array{index:int,start:int,end:int,text:string}> */
    private function split(string $text): array
    {
        $parts = [];
        $start = 0;
        $length = mb_strlen($text);
        $index = 1;
        while ($start < $length) {
            $remaining = $length - $start;
            $size = min(self::MAX_CHARS, $remaining);
            if ($remaining > self::MAX_CHARS) {
                $prefix = mb_substr($text, $start, self::MAX_CHARS);
                $size = $this->boundary($prefix);
            }
            $parts[] = [
                'index' => $index++,
                'start' => $start,
                'end' => $start + $size,
                'text' => mb_substr($text, $start, $size),
            ];
            $start += $size;
        }

        return $parts;
    }

    private function boundary(string $prefix): int
    {
        $paragraph = mb_strrpos($prefix, "\n\n");
        if ($paragraph !== false) {
            return $paragraph + 2;
        }
        for ($position = mb_strlen($prefix) - 1; $position >= 0; $position--) {
            if (in_array(mb_substr($prefix, $position, 1), ['.', '?', '!', '。', '？', '！'], true)) {
                return $position + 1;
            }
        }
        for ($position = mb_strlen($prefix) - 1; $position >= 0; $position--) {
            if (preg_match('/\s/u', mb_substr($prefix, $position, 1)) === 1) {
                return $position + 1;
            }
        }

        return self::MAX_CHARS;
    }

    /** @return array<int,string> */
    private function orderedProfile(string|array|null $profile): array
    {
        if ($profile === null || $profile === '') {
            return [];
        }
        if (is_string($profile)) {
            $profile = explode(',', $profile);
        }

        return array_values(array_map('strval', $profile));
    }

    private function stableUuid(string $identity): string
    {
        $hex = substr(hash('sha256', $identity), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
