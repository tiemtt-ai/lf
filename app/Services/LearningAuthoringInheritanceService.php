<?php

namespace App\Services;

use App\Exceptions\LearningAuthoringBasisException;
use App\Support\Ai\CanonicalJson;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Learning owner port createInheritedDraft (contract § P1-1).
 *
 * Copies an exact published Version into a NEW draft: eligible active Nodes
 * with their Definition identity and the base snapshot content (not the
 * mutable current Definition), and same-Version semantic relations whose
 * endpoints are both retained. Retired Nodes and Nodes of inactive Definitions
 * are excluded with their incident relations, in an explicit plan the admin
 * must acknowledge by hash; nothing is silently reactivated or dropped. No
 * transition/carry-forward relation, Mapping, Evidence or Mastery is copied,
 * and the base Version is never modified. Only an active customer_admin may
 * call it. Runs inside the caller's transaction.
 */
final class LearningAuthoringInheritanceService
{
    /**
     * @return array{source_graph_hash:string,plan_hash:string,eligible_node_ids:array<int,int>,excluded_node_ids:array<int,int>,excluded_relation_ids:array<int,int>}
     */
    public function preview(int $frameworkId, int $baseVersionId): array
    {
        $customerId = TenantContext::customerId() ?? throw new LearningAuthoringBasisException('basis_unavailable');
        [, $nodes, $relations] = $this->graph($customerId, $frameworkId, $baseVersionId);

        return $this->plan($nodes, $relations);
    }

    /**
     * What an admin needs to judge the plan: the Nodes it leaves out, by code and name, and why. Read-only, of the
     * tenant's own Framework and Version, at most 100; no description and no criteria.
     *
     * @param  array<int,int>  $excludedNodeIds
     * @return array<int,array{node_id:int,code:string,label:string,node_type:string,reason:string}>
     */
    public function excludedDisplay(int $frameworkId, int $baseVersionId, array $excludedNodeIds): array
    {
        $customerId = TenantContext::customerId() ?? throw new LearningAuthoringBasisException('basis_unavailable');
        if ($excludedNodeIds === []) {
            return [];
        }

        return DB::table('core_learning_nodes as nodes')
            ->join('core_learning_node_definitions as definitions', function ($join): void {
                $join->on('definitions.id', '=', 'nodes.node_definition_id')->on('definitions.customer_id', '=', 'nodes.customer_id');
            })
            ->where('nodes.customer_id', $customerId)->where('nodes.framework_id', $frameworkId)
            ->where('nodes.framework_version_id', $baseVersionId)->whereIn('nodes.id', array_slice($excludedNodeIds, 0, 100))
            ->orderBy('nodes.id')
            ->get(['nodes.id', 'nodes.code_snapshot', 'nodes.name_snapshot', 'nodes.status', 'definitions.node_type'])
            ->map(fn (object $node): array => [
                'node_id' => (int) $node->id, 'code' => (string) $node->code_snapshot, 'label' => (string) $node->name_snapshot,
                'node_type' => (string) $node->node_type, 'reason' => $node->status === 'active' ? 'definition_inactive' : 'node_retired',
            ])->all();
    }

    /**
     * @return array{result_version_id:int,node_map:array<int,int>,plan:array<string,mixed>}
     */
    public function createInheritedDraft(int $adminId, int $frameworkId, int $baseVersionId, string $versionCode, string $title, string $expectedSourceGraphHash, string $expectedPlanHash): array
    {
        $customerId = TenantContext::customerId() ?? throw new LearningAuthoringBasisException('basis_unavailable');
        $admin = DB::table('users')->where('customer_id', $customerId)->where('id', $adminId)->first(['role', 'status']);
        if ($admin === null || $admin->status !== 'active' || $admin->role !== 'customer_admin') {
            throw new LearningAuthoringBasisException('draft_unavailable');
        }
        $versionCode = trim($versionCode);
        $title = trim($title);
        if ($versionCode === '' || mb_strlen($versionCode) > 100 || $title === '' || mb_strlen($title) > 255) {
            throw new LearningAuthoringBasisException('draft_unavailable');
        }

        // Learning authoring order: Versions before Framework.
        DB::table('core_learning_framework_versions')->where('customer_id', $customerId)->where('id', $baseVersionId)->lockForUpdate()->first();
        $framework = DB::table('core_learning_frameworks')->where('customer_id', $customerId)->where('id', $frameworkId)->lockForUpdate()->first();
        if ($framework === null || $framework->status !== 'active') {
            throw new LearningAuthoringBasisException('draft_unavailable');
        }
        [$base, $nodes, $relations] = $this->graph($customerId, $frameworkId, $baseVersionId);
        $plan = $this->plan($nodes, $relations);
        if (! hash_equals($plan['source_graph_hash'], $expectedSourceGraphHash) || ! hash_equals($plan['plan_hash'], $expectedPlanHash)) {
            throw new LearningAuthoringBasisException('inheritance_plan_changed');
        }
        if ($plan['eligible_node_ids'] === []) {
            throw new LearningAuthoringBasisException('inheritance_empty');
        }

        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
        $versionId = (int) DB::table('core_learning_framework_versions')->insertGetId([
            'customer_id' => $customerId, 'framework_id' => $frameworkId,
            'version_number' => 1 + (int) DB::table('core_learning_framework_versions')->where('customer_id', $customerId)->where('framework_id', $frameworkId)->max('version_number'),
            'version_code' => $versionCode, 'title_snapshot' => $title, 'description_snapshot' => $base->description_snapshot,
            // Scale from the base snapshot, not the Framework's current default.
            'mastery_scale_key' => $base->mastery_scale_key, 'mastery_scale_version' => $base->mastery_scale_version,
            'mastery_scale_snapshot' => $base->mastery_scale_snapshot,
            'status' => 'draft_snapshot', 'published_at' => null, 'published_by' => null, 'deprecated_at' => null,
            'deprecated_by' => null, 'archived_at' => null, 'archived_by' => null,
            'created_by' => $adminId, 'updated_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $map = [];
        foreach ($plan['eligible_node_ids'] as $oldId) {
            $node = $nodes[$oldId];
            $map[$oldId] = (int) DB::table('core_learning_nodes')->insertGetId([
                'customer_id' => $customerId, 'framework_id' => $frameworkId, 'framework_version_id' => $versionId,
                'node_definition_id' => $node->node_definition_id, 'code_snapshot' => $node->code_snapshot,
                'name_snapshot' => $node->name_snapshot, 'description_snapshot' => $node->description_snapshot,
                'criteria_snapshot' => $node->criteria_snapshot, 'sequence' => $node->sequence, 'status' => 'active',
                'created_by' => $adminId, 'updated_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($relations as $relation) {
            if (in_array((int) $relation->id, $plan['excluded_relation_ids'], true)) {
                continue;
            }
            DB::table('core_learning_node_relations')->insert([
                'customer_id' => $customerId, 'framework_id' => $frameworkId, 'owning_framework_version_id' => $versionId,
                'relation_scope' => 'semantic', 'relation_type' => $relation->relation_type,
                'source_learning_node_id' => $map[(int) $relation->source_learning_node_id],
                'target_learning_node_id' => $map[(int) $relation->target_learning_node_id],
                'source_framework_version_id' => $versionId, 'target_framework_version_id' => $versionId,
                'review_status' => 'not_required', 'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return ['result_version_id' => $versionId, 'node_map' => $map, 'plan' => $plan];
    }

    /** @return array{0:object,1:array<int,object>,2:array<int,object>} */
    private function graph(int $customerId, int $frameworkId, int $baseVersionId): array
    {
        $base = DB::table('core_learning_framework_versions')->where('customer_id', $customerId)
            ->where('framework_id', $frameworkId)->where('id', $baseVersionId)->where('status', 'published')->first();
        if ($base === null) {
            throw new LearningAuthoringBasisException('basis_unavailable');
        }
        $nodes = DB::table('core_learning_nodes as nodes')
            ->join('core_learning_node_definitions as definitions', function ($join): void {
                $join->on('definitions.id', '=', 'nodes.node_definition_id')->on('definitions.customer_id', '=', 'nodes.customer_id');
            })
            ->where('nodes.customer_id', $customerId)->where('nodes.framework_version_id', $baseVersionId)
            ->orderBy('nodes.id')
            ->get(['nodes.*', 'definitions.status as definition_status'])
            ->keyBy(fn (object $n): int => (int) $n->id)->all();
        $relations = DB::table('core_learning_node_relations')->where('customer_id', $customerId)
            ->where('owning_framework_version_id', $baseVersionId)->where('relation_scope', 'semantic')
            ->orderBy('id')->get()->all();

        return [$base, $nodes, $relations];
    }

    /**
     * @param  array<int,object>  $nodes
     * @param  array<int,object>  $relations
     * @return array{source_graph_hash:string,plan_hash:string,eligible_node_ids:array<int,int>,excluded_node_ids:array<int,int>,excluded_relation_ids:array<int,int>}
     */
    private function plan(array $nodes, array $relations): array
    {
        $eligible = [];
        $excluded = [];
        foreach ($nodes as $id => $node) {
            if ($node->status === 'active' && $node->definition_status === 'active') {
                $eligible[] = (int) $id;
            } else {
                $excluded[] = (int) $id;
            }
        }
        $excludedRelations = [];
        foreach ($relations as $relation) {
            $source = (int) $relation->source_learning_node_id;
            $target = (int) $relation->target_learning_node_id;
            if (! isset($nodes[$source], $nodes[$target])) {
                // A dangling endpoint is an integrity failure, not an exclusion.
                throw new LearningAuthoringBasisException('inheritance_integrity');
            }
            if (in_array($source, $excluded, true) || in_array($target, $excluded, true)) {
                $excludedRelations[] = (int) $relation->id;
            }
        }

        $sourceGraphHash = CanonicalJson::hash([
            'nodes' => array_values(array_map(fn (object $n): array => [
                'id' => (int) $n->id, 'definition_id' => (int) $n->node_definition_id, 'code' => $n->code_snapshot,
                'name' => $n->name_snapshot, 'description' => $n->description_snapshot, 'criteria' => $n->criteria_snapshot,
                'sequence' => (int) $n->sequence, 'status' => $n->status, 'definition_status' => $n->definition_status,
            ], $nodes)),
            'relations' => array_map(fn (object $r): array => [
                'id' => (int) $r->id, 'type' => $r->relation_type,
                'source' => (int) $r->source_learning_node_id, 'target' => (int) $r->target_learning_node_id,
            ], $relations),
        ]);
        $planBody = ['eligible_node_ids' => $eligible, 'excluded_node_ids' => $excluded, 'excluded_relation_ids' => $excludedRelations];

        return ['source_graph_hash' => $sourceGraphHash, 'plan_hash' => CanonicalJson::hash($planBody + ['source_graph_hash' => $sourceGraphHash])] + $planBody;
    }
}
