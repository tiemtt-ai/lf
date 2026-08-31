<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StructuredExtractionPersistenceService
{
    /**
     * Validate a complete provider result before writing any row. The caller
     * owns the surrounding transaction.
     *
     * @param  array<string, mixed>  $result
     * @return array{output_type: string, output_id: int, coverage: array<string, mixed>}
     */
    public function persist(int $customerId, object $media, object $job, string $locale, array $result): array
    {
        $regions = array_values($result['regions'] ?? []);
        $tables = array_values($result['tables'] ?? []);
        $canonicalTextRows = $this->canonicalTextRows($customerId, $media, $job, $locale);
        $this->validate($regions, $tables, $canonicalTextRows);

        $now = now();
        $regionIds = [];
        foreach ($regions as $index => $region) {
            $regionIds[$index] = DB::table('media_extracted_regions')->insertGetId([
                'customer_id' => $customerId,
                'media_file_id' => $media->id,
                'processing_job_id' => $job->id,
                'locale' => $locale,
                'locator_type' => 'region',
                'locator_value' => (string) $region['locator_value'],
                'page' => (int) $region['page'],
                'ordinal' => (int) $region['ordinal'],
                'reading_order' => (int) $region['reading_order'],
                'role' => (string) $region['role'],
                'bbox_x' => $region['bbox']['x'] ?? null,
                'bbox_y' => $region['bbox']['y'] ?? null,
                'bbox_width' => $region['bbox']['width'] ?? null,
                'bbox_height' => $region['bbox']['height'] ?? null,
                'text' => $region['text'] ?? null,
                'char_count' => isset($region['text']) ? mb_strlen((string) $region['text']) : null,
                'confidence_score' => $region['confidence_score'] ?? null,
                'extraction_method' => $region['extraction_method'] ?? 'embedded_text',
                'provider' => $job->provider,
                'crop_storage_key' => $region['crop']['storage_key'] ?? null,
                'crop_mime_type' => $region['crop']['mime_type'] ?? null,
                'crop_width' => $region['crop']['width'] ?? null,
                'crop_height' => $region['crop']['height'] ?? null,
                'crop_bytes' => $region['crop']['bytes'] ?? null,
                'processing_version' => $job->processing_version,
                'source_fingerprint' => $job->source_fingerprint,
                'status' => 'ready',
                'metadata' => isset($region['metadata']) ? json_encode($region['metadata']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $firstTableId = null;
        foreach ($tables as $table) {
            $regionIndex = $table['region_index'] ?? null;
            $tableId = DB::table('media_extracted_tables')->insertGetId([
                'customer_id' => $customerId,
                'media_file_id' => $media->id,
                'processing_job_id' => $job->id,
                'region_id' => $regionIndex === null ? null : $regionIds[(int) $regionIndex],
                'locale' => $locale,
                'locator_type' => (string) $table['locator_type'],
                'locator_value' => (string) $table['locator_value'],
                'sequence' => (int) $table['sequence'],
                'title' => $table['title'] ?? null,
                'row_count' => (int) $table['row_count'],
                'column_count' => (int) $table['column_count'],
                'has_header' => (bool) ($table['has_header'] ?? false),
                'confidence_score' => $table['confidence_score'] ?? null,
                'extraction_method' => (string) $table['extraction_method'],
                'provider' => $job->provider,
                'processing_version' => $job->processing_version,
                'source_fingerprint' => $job->source_fingerprint,
                'status' => 'ready',
                'metadata' => isset($table['metadata']) ? json_encode($table['metadata']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $firstTableId ??= (int) $tableId;

            foreach ($table['cells'] ?? [] as $cell) {
                DB::table('media_table_cells')->insert([
                    'customer_id' => $customerId,
                    'extracted_table_id' => $tableId,
                    'row_index' => (int) $cell['row'],
                    'column_index' => (int) $cell['column'],
                    'row_span' => (int) ($cell['row_span'] ?? 1),
                    'column_span' => (int) ($cell['column_span'] ?? 1),
                    'is_header' => (bool) ($cell['is_header'] ?? false),
                    'text' => $cell['text'] ?? null,
                    'char_count' => isset($cell['text']) ? mb_strlen((string) $cell['text']) : null,
                    'confidence_score' => $cell['confidence_score'] ?? null,
                    'metadata' => isset($cell['metadata']) ? json_encode($cell['metadata']) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (['media_extracted_regions', 'media_extracted_tables'] as $table) {
            DB::table($table)->where('customer_id', $customerId)->where('media_file_id', $media->id)
                ->where('locale', $locale)->where('status', 'ready')
                ->where(fn ($query) => $query->where('processing_version', '<>', $job->processing_version)
                    ->orWhere('source_fingerprint', '<>', $job->source_fingerprint))
                ->update(['status' => 'archived', 'updated_at' => $now]);
        }

        $coverage = $this->structureCoverage($canonicalTextRows, $regions);

        if ($regionIds !== []) {
            return ['output_type' => 'extracted_region', 'output_id' => (int) $regionIds[0], 'coverage' => $coverage];
        }
        if ($firstTableId !== null) {
            return ['output_type' => 'extracted_table', 'output_id' => $firstTableId, 'coverage' => $coverage];
        }

        throw new RuntimeException('no_extractable_text');
    }

    /**
     * Do lech giua text canonical va cau truc, roi ghi lai thanh so.
     *
     * Mot trang co text nhung khong co region la mot su vang mat IM LANG: consumer
     * hoi region se nhan mang rong, va khong the phan biet "trang trang" voi "trang
     * co noi dung nhung layout model truot". Do tren tai lieu that: PDF text-layer
     * dat coverage day du, con trang scan thi khong dam bao.
     *
     * Ghi so o day khong sua duoc do lech — no chi lam do lech tro thanh thu truy
     * van duoc, thay vi mot phat hien tinh co.
     *
     * @param  array<int, array<string, mixed>>  $regions
     * @return array<string, mixed>
     */
    private function structureCoverage(Collection $canonicalTextRows, array $regions): array
    {
        $textPages = $canonicalTextRows->where('char_count', '>', 0)->where('locator_type', 'page')->pluck('locator_value')
            ->map(static fn ($value): int => (int) $value)
            ->unique()->sort()->values()->all();

        $regionPages = collect($regions)->pluck('page')
            ->map(static fn ($value): int => (int) $value)
            ->unique()->sort()->values()->all();

        $missing = array_values(array_diff($textPages, $regionPages));

        return [
            'pages_with_text' => count($textPages),
            'pages_with_regions' => count($regionPages),
            'pages_text_without_structure' => $missing,
        ];
    }

    private function canonicalTextRows(int $customerId, object $media, object $job, string $locale): Collection
    {
        return app(DocumentCanonicalRevision::class)->forStructure($customerId, $media, $job, $locale);
    }

    /** @param array<int, array<string, mixed>> $regions @param array<int, array<string, mixed>> $tables */
    private function validate(array $regions, array $tables, Collection $canonicalTextRows): void
    {
        $maxPerPage = (int) config('media.processing.structured_extraction.max_regions_per_page', 100);
        $maxRegions = (int) config('media.processing.structured_extraction.max_regions_per_document', 5000);
        $maxCells = (int) config('media.processing.structured_extraction.max_table_cells_per_document', 200000);
        $maxChars = (int) config('media.processing.structured_extraction.max_extracted_characters', 500000);
        $maxCropBytes = (int) config('media.processing.structured_extraction.max_crop_bytes_per_document', 67108864);

        if (count($regions) > $maxRegions || count($regions) !== count(array_unique(array_column($regions, 'reading_order')))) {
            throw new RuntimeException('structured_extraction_too_large');
        }
        if ($regions !== [] && array_column($regions, 'reading_order') !== range(1, count($regions))) {
            throw new RuntimeException('structured_extraction_invalid');
        }

        $byPage = [];
        $cropBytes = 0;
        foreach ($regions as $index => $region) {
            $page = (int) ($region['page'] ?? 0);
            $byPage[$page][] = $region;
            if (($region['locator_value'] ?? null) !== $page.'#'.($region['ordinal'] ?? null)) {
                throw new RuntimeException('structured_extraction_invalid');
            }
            $crop = $region['crop'] ?? null;
            if ($crop !== null) {
                $complete = isset($crop['storage_key'], $crop['mime_type'])
                    && (int) ($crop['width'] ?? 0) > 0
                    && (int) ($crop['height'] ?? 0) > 0
                    && (int) ($crop['bytes'] ?? 0) > 0;
                if (! $complete || ! is_array($region['bbox'] ?? null)) {
                    throw new RuntimeException('structured_extraction_invalid');
                }
                $cropBytes += (int) $crop['bytes'];
            }
            // Owner quyet dinh 2026-08-28 (DOC-CONFLICT-0022): region `figure`
            // DUOC mang text quan sat duoc trong bbox cua chinh no. Guard cu cam
            // dieu ma ADR-0019 § D7 diem 3 yeu cau.
        }
        foreach ($byPage as $pageRegions) {
            if (count($pageRegions) > $maxPerPage || ($pageRegions !== [] && array_column($pageRegions, 'ordinal') !== range(1, count($pageRegions)))) {
                throw new RuntimeException('structured_extraction_too_large');
            }
        }

        $cellCount = 0;
        $structuredChars = 0;
        foreach ($regions as $region) {
            $structuredChars += mb_strlen((string) ($region['text'] ?? ''));
        }
        if ($tables !== [] && array_column($tables, 'sequence') !== range(1, count($tables))) {
            throw new RuntimeException('structured_extraction_invalid');
        }
        foreach ($tables as $table) {
            $cells = array_values($table['cells'] ?? []);
            if ($cells === [] || (int) ($table['row_count'] ?? 0) < 1 || (int) ($table['column_count'] ?? 0) < 1) {
                throw new RuntimeException('structured_extraction_invalid');
            }
            $cellCount += count($cells);
            if ($cellCount > $maxCells) {
                throw new RuntimeException('structured_extraction_too_large');
            }
            foreach ($cells as $cell) {
                $row = (int) ($cell['row'] ?? 0);
                $column = (int) ($cell['column'] ?? 0);
                $rowSpan = (int) ($cell['row_span'] ?? 1);
                $columnSpan = (int) ($cell['column_span'] ?? 1);
                if ($row < 1 || $column < 1 || $rowSpan < 1 || $columnSpan < 1 || $row + $rowSpan - 1 > (int) $table['row_count']
                    || $column + $columnSpan - 1 > (int) $table['column_count']) {
                    throw new RuntimeException('structured_extraction_invalid');
                }
                $structuredChars += mb_strlen((string) ($cell['text'] ?? ''));
            }
            app(DocumentCellOverlap::class)->validate($cells);
            $regionIndex = $table['region_index'] ?? null;
            if (($table['locator_type'] ?? null) === 'region'
                && ($regionIndex === null
                    || ! isset($regions[(int) $regionIndex])
                    || $regions[(int) $regionIndex]['role'] !== 'table'
                    || (string) ($table['locator_value'] ?? '') !== (string) $regions[(int) $regionIndex]['locator_value'])) {
                throw new RuntimeException('structured_extraction_invalid');
            }
            if (($table['locator_type'] ?? null) === 'sheet' && $regionIndex !== null) {
                throw new RuntimeException('structured_extraction_invalid');
            }
        }

        $pageChars = (int) $canonicalTextRows->sum('char_count');
        if ($cellCount > $maxCells || $pageChars + $structuredChars > $maxChars || $cropBytes > $maxCropBytes) {
            throw new RuntimeException('structured_extraction_too_large');
        }
    }
}
