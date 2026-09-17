<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Learning-owned writer for canonical Mappings, called from the Course publish
 * transaction and nowhere else.
 *
 * On the token: it is an internal call-path integrity marker, not an
 * authorization capability, and it must not be read as one in review. Both
 * sides derive it from the same publish identifiers with the same application
 * key, so any caller able to reach this method can also mint it. What it
 * actually catches is a call assembled from the wrong publish context — a
 * mismatched Template, Course Version or actor — which is a coherence bug, not
 * an attacker.
 *
 * The real protections are below it and are unconditional: tenant scoping,
 * revalidation that the Node is still active inside a still-published Framework
 * Version, and that the source survived the snapshot. Those hold no matter who
 * calls.
 */
final class LearningMappingPromotionService
{
    /**
     * @param  array<int,array<string,mixed>>  $aiLineage  Step 7 content-free lineage keyed by Intent id,
     *                                                     verified by the AI owner port for every ai_proposal Intent
     */
    public function promote(int $customerId, int $templateId, int $courseVersionId, array $lessonMap, array $activityMap, int $actorId, $now, string $callPathToken, array $aiLineage = []): void
    {
        $expected = hash_hmac('sha256', implode(':', [$customerId, $templateId, $courseVersionId, $actorId]), (string) config('app.key'));
        if (! hash_equals($expected, $callPathToken)) {
            throw ValidationException::withMessages(['publish' => 'The Learning Mapping promotion request does not match its publish context.']);
        }
        if (! Schema::hasTable('core_course_template_learning_mapping_intents')) {
            throw ValidationException::withMessages(['publish' => 'Learning Mapping Intent storage is unavailable.']);
        }
        $intents = DB::table('core_course_template_learning_mapping_intents')->where('customer_id', $customerId)->where('template_id', $templateId)->get();
        foreach ($intents as $intent) {
            $sourceMap = $intent->source_type === 'course_template_lesson' ? $lessonMap : ($intent->source_type === 'course_template_activity' ? $activityMap : null);
            if ($sourceMap === null || ! array_key_exists($intent->source_id, $sourceMap)) {
                throw ValidationException::withMessages(['publish' => 'A Learning Mapping source is no longer present in this Course Template.']);
            }
            $node = DB::table('core_learning_nodes as nodes')->join('core_learning_framework_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'nodes.framework_version_id')->where('versions.customer_id', $customerId);
            })->where('nodes.customer_id', $customerId)->where('nodes.id', $intent->learning_node_id)
                ->where('nodes.framework_id', $intent->framework_id)->where('nodes.framework_version_id', $intent->framework_version_id)
                ->where('nodes.status', 'active')->where('versions.status', 'published')->first(['nodes.id']);
            if (! $node) {
                throw ValidationException::withMessages(['publish' => 'A Learning Node or Framework Version is no longer publishable.']);
            }
            $sourceType = $intent->source_type === 'course_template_lesson' ? 'course_version_lesson' : 'course_version_activity';
            $sourceId = $sourceMap[$intent->source_id];
            $sourceTable = $intent->source_type === 'course_template_lesson' ? 'core_course_template_lessons' : 'core_course_template_activities';
            $sourceLabel = DB::table($sourceTable)->where('customer_id', $customerId)->where('template_id', $templateId)->where('id', $intent->source_id)->value('title');
            if ($sourceLabel === null) {
                throw ValidationException::withMessages(['publish' => 'A Learning Mapping source no longer exists.']);
            }
            $snapshot = [
                'label' => $sourceLabel,
                'source_type' => $sourceType,
                'course_version_id' => $courseVersionId,
                'source_version_identity' => (string) $courseVersionId,
            ];
            if (($intent->origin ?? 'manual') === 'ai_proposal') {
                // Immutable Mapping -> Proposal audit path; survives Intent deletion.
                $snapshot['ai_lineage'] = $this->validatedLineage($aiLineage[(int) $intent->id] ?? null);
            } elseif (array_key_exists((int) $intent->id, $aiLineage)) {
                throw ValidationException::withMessages(['publish' => 'AI lineage was supplied for a manual Learning Mapping Intent.']);
            }
            $snapshot = json_encode($snapshot, JSON_THROW_ON_ERROR);
            $mapping = [
                'customer_id' => $customerId, 'learning_node_id' => $intent->learning_node_id, 'source_type' => $sourceType,
                'source_id' => $sourceId, 'source_discriminator' => (string) $courseVersionId, 'mapping_role' => $intent->mapping_role,
                'weight' => $intent->weight, 'source_snapshot' => $snapshot, 'created_by' => $actorId, 'created_at' => $now,
                'invalidated_at' => null, 'invalidated_by' => null, 'invalidation_reason' => null,
            ];
            try {
                DB::table('core_learning_node_mappings')->insert($mapping);
            } catch (QueryException $exception) {
                if (! $this->isDuplicateMapping($exception)) {
                    throw $exception;
                }
                $exists = DB::table('core_learning_node_mappings')->where('customer_id', $customerId)
                    ->where('source_type', $sourceType)->where('source_id', $sourceId)
                    ->where('source_discriminator', (string) $courseVersionId)->where('learning_node_id', $intent->learning_node_id)
                    ->where('mapping_role', $intent->mapping_role)->exists();
                if (! $exists) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Learning validates the shape it persists: identifiers and hashes only,
     * never proposal text, excerpts or prompts.
     *
     * @return array<string,mixed>
     */
    private function validatedLineage(mixed $lineage): array
    {
        $keys = ['proposal_id', 'proposal_uuid', 'revision_id', 'payload_hash', 'target_review_id', 'target_hash', 'context_review_id', 'context_hash'];
        $valid = is_array($lineage) && array_keys($lineage) === $keys
            && is_int($lineage['proposal_id']) && is_int($lineage['revision_id']) && is_int($lineage['target_review_id'])
            && ($lineage['context_review_id'] === null || is_int($lineage['context_review_id']))
            && is_string($lineage['proposal_uuid']) && preg_match('/\A[0-9a-f-]{36}\z/', $lineage['proposal_uuid']) === 1;
        foreach (['payload_hash', 'target_hash', 'context_hash'] as $hash) {
            $valid = $valid && is_string($lineage[$hash]) && preg_match('/\A[0-9a-f]{64}\z/', $lineage[$hash]) === 1;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['publish' => 'An AI-origin Learning Mapping Intent has no verified lineage.']);
        }

        return $lineage;
    }

    private function isDuplicateMapping(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'idx_lrn_019');
    }
}
