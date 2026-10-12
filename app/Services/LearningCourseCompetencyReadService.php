<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Learning-owned read path for Teacher Judgment (Course Scope Amendment,
 * Owner 2026-10-11): which Framework Versions and competencies a published
 * Course Version offers for judgment.
 *
 * It reads only Learning tables. The Course Version is identified by the
 * source_discriminator LearningMappingPromotionService writes on each canonical
 * mapping, so no Course table is joined. Authorization (an active teacher
 * assignment to a cohort of that Course Version) belongs to the caller.
 */
final class LearningCourseCompetencyReadService
{
    /** @return Collection<int, object> published Versions mapped by the Course Version, with their frozen scale */
    public function bases(int $customerId, int $courseVersionId): Collection
    {
        $basisIds = $this->mappedNodes($customerId, $courseVersionId)
            ->pluck('framework_version_id')->unique()->values()->all();
        if ($basisIds === []) {
            return collect();
        }

        return DB::table('core_learning_framework_versions as versions')
            ->join('core_learning_frameworks as frameworks', function ($join): void {
                $join->on('frameworks.id', '=', 'versions.framework_id')
                    ->on('frameworks.customer_id', '=', 'versions.customer_id');
            })
            ->where('versions.customer_id', $customerId)
            ->whereIn('versions.id', $basisIds)
            ->where('versions.status', 'published')
            ->orderBy('frameworks.name')->orderBy('versions.version_number')
            ->get(['versions.id', 'versions.framework_id', 'versions.version_number', 'versions.title_snapshot',
                'versions.mastery_scale_snapshot', 'frameworks.name as framework_name'])
            ->map(function (object $basis): object {
                $basis->levels = json_decode((string) $basis->mastery_scale_snapshot, true, 512, JSON_THROW_ON_ERROR)['levels'] ?? [];

                return $basis;
            });
    }

    /**
     * Every active competency of the basis, the ones the Course Version mapped
     * first (option A), each carrying the Lesson/Activity labels of its mappings.
     *
     * @return Collection<int, object>
     */
    public function competencies(int $customerId, int $courseVersionId, int $basisId): Collection
    {
        $labels = $this->mappedNodes($customerId, $courseVersionId)
            ->where('framework_version_id', $basisId)
            ->groupBy('learning_node_id')
            ->map(fn (Collection $rows): array => $rows->pluck('label')->filter()->unique()->values()->all());

        return DB::table('core_learning_nodes')
            ->where('customer_id', $customerId)->where('framework_version_id', $basisId)->where('status', 'active')
            ->orderBy('sequence')->orderBy('id')
            ->get(['id', 'node_definition_id', 'code_snapshot', 'name_snapshot', 'description_snapshot', 'sequence'])
            ->map(function (object $node) use ($labels): object {
                $node->mapped = $labels->has($node->id);
                $node->labels = $labels->get($node->id, []);

                return $node;
            })
            ->sortBy(fn (object $node): string => sprintf('%d:%010d:%010d', $node->mapped ? 0 : 1, $node->sequence, $node->id))
            ->values();
    }

    /** @return Collection<int, object> learning_node_id, framework_version_id and source label of each live mapping */
    private function mappedNodes(int $customerId, int $courseVersionId): Collection
    {
        return DB::table('core_learning_node_mappings as mappings')
            ->join('core_learning_nodes as nodes', function ($join): void {
                $join->on('nodes.id', '=', 'mappings.learning_node_id')
                    ->on('nodes.customer_id', '=', 'mappings.customer_id');
            })
            ->where('mappings.customer_id', $customerId)
            ->whereIn('mappings.source_type', ['course_version_lesson', 'course_version_activity'])
            ->where('mappings.source_discriminator', (string) $courseVersionId)
            ->whereNull('mappings.invalidated_at')
            ->get(['mappings.learning_node_id', 'nodes.framework_version_id', 'mappings.source_snapshot'])
            ->map(function (object $row): object {
                $snapshot = json_decode((string) $row->source_snapshot, true) ?: [];
                $row->label = is_string($snapshot['label'] ?? null) ? $snapshot['label'] : null;

                return $row;
            });
    }
}
