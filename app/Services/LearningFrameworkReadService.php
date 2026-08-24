<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner boundary for customer-admin reads of the Learning authoring graph.
 *
 * This is deliberately separate from LearningRuntimeAccess: runtime access
 * denies external Learning reads, while this service owns the authorised,
 * tenant-scoped administrative authoring read path.
 */
final class LearningFrameworkReadService
{
    /** @return Collection<int, object> */
    public function listFrameworks(int $actorId): Collection
    {
        $customerId = $this->customerAdminTenantId($actorId);

        return DB::table('core_learning_frameworks as frameworks')
            ->where('frameworks.customer_id', $customerId)
            ->leftJoin('core_learning_framework_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.framework_id', '=', 'frameworks.id')
                    ->where('versions.customer_id', $customerId);
            })
            ->select('frameworks.*')
            ->selectRaw('COUNT(versions.id) as version_count')
            ->selectRaw("SUM(CASE WHEN versions.status = 'draft_snapshot' THEN 1 ELSE 0 END) as draft_count")
            ->selectRaw("SUM(CASE WHEN versions.status = 'published' THEN 1 ELSE 0 END) as published_count")
            ->groupBy([
                'frameworks.id', 'frameworks.customer_id', 'frameworks.code', 'frameworks.name',
                'frameworks.description', 'frameworks.default_mastery_scale_key',
                'frameworks.default_mastery_scale_version', 'frameworks.default_mastery_scale',
                'frameworks.status', 'frameworks.archived_at', 'frameworks.archived_by',
                'frameworks.created_by', 'frameworks.updated_by', 'frameworks.created_at', 'frameworks.updated_at',
            ])
            ->orderBy('frameworks.name')
            ->get();
    }

    /** @return array{framework: object, masteryScale: array<string, mixed>, versions: Collection, definitions: Collection, nodesByVersion: Collection} */
    public function detail(int $actorId, int $frameworkId): array
    {
        $customerId = $this->customerAdminTenantId($actorId);
        $framework = DB::table('core_learning_frameworks')
            ->where('customer_id', $customerId)->where('id', $frameworkId)->first();

        if ($framework === null) {
            abort(404);
        }

        $versions = DB::table('core_learning_framework_versions')
            ->where('customer_id', $customerId)->where('framework_id', $frameworkId)
            ->orderByDesc('version_number')->get();
        $definitions = DB::table('core_learning_node_definitions')
            ->where('customer_id', $customerId)->where('framework_id', $frameworkId)
            ->select('core_learning_node_definitions.*')
            ->selectRaw("EXISTS (SELECT 1 FROM core_learning_nodes n JOIN core_learning_framework_versions v ON v.id = n.framework_version_id AND v.customer_id = n.customer_id WHERE n.customer_id = core_learning_node_definitions.customer_id AND n.node_definition_id = core_learning_node_definitions.id AND v.status <> 'draft_snapshot') AS identity_locked")
            ->orderBy('node_type')->orderBy('canonical_name')->get();
        $nodesByVersion = DB::table('core_learning_nodes')
            ->where('customer_id', $customerId)->where('framework_id', $frameworkId)
            ->orderBy('framework_version_id')->orderBy('sequence')->get()->groupBy('framework_version_id');

        return [
            'framework' => $framework,
            'masteryScale' => json_decode($framework->default_mastery_scale, true, 512, JSON_THROW_ON_ERROR),
            'versions' => $versions,
            'definitions' => $definitions,
            'nodesByVersion' => $nodesByVersion,
        ];
    }

    public function versionBelongsToFramework(int $actorId, int $versionId, int $frameworkId): bool
    {
        $customerId = $this->customerAdminTenantId($actorId);

        return DB::table('core_learning_framework_versions')
            ->where('customer_id', $customerId)->where('id', $versionId)->where('framework_id', $frameworkId)->exists();
    }

    /** @return Collection<int, object> */
    public function publishedVersionsWithNodes(int $actorId): Collection
    {
        $customerId = $this->customerAdminTenantId($actorId);

        return DB::table('core_learning_framework_versions as versions')
            ->join('core_learning_frameworks as frameworks', function ($join) use ($customerId): void {
                $join->on('frameworks.id', '=', 'versions.framework_id')
                    ->where('frameworks.customer_id', $customerId)
                    ->where('frameworks.status', 'active');
            })
            ->where('versions.customer_id', $customerId)
            ->where('versions.status', 'published')
            ->select('versions.id as framework_version_id', 'versions.framework_id', 'versions.version_code', 'versions.title_snapshot', 'frameworks.name as framework_name')
            ->orderBy('frameworks.name')->orderByDesc('versions.version_number')->get();
    }

    /** @return Collection<int, object> */
    public function activeNodesForPublishedVersion(int $actorId, int $frameworkId, int $versionId): Collection
    {
        $customerId = $this->customerAdminTenantId($actorId);
        $this->assertPublishedVersion($customerId, $frameworkId, $versionId);

        return DB::table('core_learning_nodes')
            ->where('customer_id', $customerId)->where('framework_id', $frameworkId)
            ->where('framework_version_id', $versionId)->where('status', 'active')
            ->orderBy('sequence')->orderBy('id')->get();
    }

    /**
     * Display labels for versioned Nodes an authorised Course author already
     * holds identifiers for.
     *
     * Owner decision G2-N1 keeps every read of `core_learning_*` behind this
     * service, so a Course-owned screen listing its Mapping Intents cannot join
     * the Learning tables itself just to render a Node name.
     *
     * @param  array<int, int|string>  $nodeIds
     * @return Collection<int, object> keyed by Node id
     */
    public function nodeLabels(int $actorId, array $nodeIds): Collection
    {
        $customerId = $this->customerAdminTenantId($actorId);
        $ids = array_values(array_unique(array_map('intval', $nodeIds)));

        if ($ids === []) {
            return collect();
        }

        return DB::table('core_learning_nodes')
            ->where('customer_id', $customerId)
            ->whereIn('id', $ids)
            ->get(['id', 'code_snapshot', 'name_snapshot'])
            ->keyBy(fn (object $node): int => (int) $node->id);
    }

    public function assertPublishedVersionForAuthor(int $actorId, int $frameworkId, int $versionId): void
    {
        $this->assertPublishedVersion($this->customerAdminTenantId($actorId), $frameworkId, $versionId);
    }

    private function assertPublishedVersion(int $customerId, int $frameworkId, int $versionId): void
    {
        $valid = DB::table('core_learning_framework_versions as versions')
            ->join('core_learning_frameworks as frameworks', function ($join) use ($customerId): void {
                $join->on('frameworks.id', '=', 'versions.framework_id')
                    ->where('frameworks.customer_id', $customerId)->where('frameworks.status', 'active');
            })
            ->where('versions.customer_id', $customerId)->where('versions.framework_id', $frameworkId)
            ->where('versions.id', $versionId)->where('versions.status', 'published')->exists();
        if (! $valid) {
            throw ValidationException::withMessages([
                'framework_version_id' => 'The selected Learning Framework Version is not available for authoring.',
            ]);
        }
    }

    private function customerAdminTenantId(int $actorId): int
    {
        $customerId = TenantContext::customerId();
        if ($customerId === null) {
            throw new AuthorizationException('Learning authoring read requires a tenant context.');
        }

        $actor = DB::table('users')->where('customer_id', $customerId)->where('id', $actorId)->first();
        if ($actor === null || $actor->status !== 'active' || $actor->role !== 'customer_admin') {
            throw new AuthorizationException('Learning authoring read is restricted to customer_admin.');
        }

        return $customerId;
    }
}
