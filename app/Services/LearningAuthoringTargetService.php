<?php

namespace App\Services;

use App\Exceptions\LearningAuthoringBasisException;
use App\Support\Ai\CanonicalJson;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Learning owner ports for Step 7 handoff (contract § Owner-service ports):
 * approveProposedNode and targetSnapshot.
 *
 * Node creation goes through LearningFrameworkAuthoringService, whose own
 * customer_admin guard stays in force — this port adds no authority. The
 * caller's transaction encloses the call, so the Node write and the AI receipt
 * commit or roll back together.
 */
final class LearningAuthoringTargetService
{
    public const TARGET_SCHEMA = 'learning-target-v1';

    /** Dependency traversal bound; beyond it the snapshot fails closed. */
    public const MAX_DEPENDENCY_NODES = 1000;

    public function __construct(private readonly LearningFrameworkAuthoringService $authoring) {}

    /**
     * Create, or reuse by exact code, the reviewed Definition and its Node in
     * an explicit draft Version. Reuse is exact identity only: a Definition
     * with the same code but another type or an archived status is a conflict,
     * never silently repurposed; semantic similarity is not reuse.
     *
     * @param  array{code:string,label:string,node_type:string,criteria:mixed}  $proposed
     * @return array{node_id:int,definition_id:int,result_basis_hash:string,reused_definition:bool,reused_node:bool}
     */
    public function approveProposedNode(int $adminId, int $frameworkId, int $draftVersionId, array $proposed): array
    {
        $customerId = TenantContext::customerId() ?? throw new LearningAuthoringBasisException('basis_unavailable');

        $version = DB::table('core_learning_framework_versions')->where('customer_id', $customerId)
            ->where('id', $draftVersionId)->where('framework_id', $frameworkId)->lockForUpdate()->first();
        if ($version === null || $version->status !== 'draft_snapshot') {
            throw new LearningAuthoringBasisException('draft_unavailable');
        }

        try {
            $definition = DB::table('core_learning_node_definitions')->where('customer_id', $customerId)
                ->where('framework_id', $frameworkId)->where('code', $proposed['code'])->lockForUpdate()->first();
            $reusedDefinition = $definition !== null;
            if ($definition === null) {
                $definition = $this->authoring->createDefinition($adminId, [
                    'framework_id' => $frameworkId, 'code' => $proposed['code'],
                    'node_type' => $proposed['node_type'], 'canonical_name' => $proposed['label'],
                ]);
            } elseif ($definition->status !== 'active' || $definition->node_type !== $proposed['node_type']
                || $definition->canonical_name !== $proposed['label']) {
                throw new LearningAuthoringBasisException('definition_conflict');
            }

            $node = DB::table('core_learning_nodes')->where('customer_id', $customerId)
                ->where('framework_version_id', $draftVersionId)->where('node_definition_id', $definition->id)->first();
            $reusedNode = $node !== null;
            if ($node === null) {
                $node = $this->authoring->createNode($adminId, [
                    'framework_version_id' => $draftVersionId, 'node_definition_id' => $definition->id,
                    'criteria' => $proposed['criteria'],
                ]);
            } elseif ($node->status !== 'active' || $node->name_snapshot !== $proposed['label']
                || CanonicalJson::hash($node->criteria_snapshot === null ? null : json_decode($node->criteria_snapshot, true)) !== CanonicalJson::hash($proposed['criteria'])) {
                throw new LearningAuthoringBasisException('definition_conflict');
            }
        } catch (DomainException) {
            // Learning's own guard refused (actor, archived Framework, draft state).
            throw new LearningAuthoringBasisException('draft_unavailable');
        }

        return [
            'node_id' => (int) $node->id,
            'definition_id' => (int) $definition->id,
            'result_basis_hash' => $this->versionContentHash($customerId, $frameworkId, $draftVersionId),
            'reused_definition' => $reusedDefinition,
            'reused_node' => $reusedNode,
        ];
    }

    /**
     * Content snapshot of one target Node and its mapping-relevant
     * dependencies (contract § Hash and dependency definitions): every incident
     * semantic relation, plus transitive prerequisite/part_of in both
     * directions; supports is not expanded; version_transition is excluded.
     * Node status and Version lifecycle are returned live, outside the hash.
     *
     * @return array{snapshot:array<string,mixed>,target_hash:string,node_status:string,version_status:string,definition_id:int}
     */
    public function targetSnapshot(int $frameworkId, int $versionId, int $nodeId): array
    {
        $customerId = TenantContext::customerId() ?? throw new LearningAuthoringBasisException('target_unavailable');

        $version = DB::table('core_learning_framework_versions')->where('customer_id', $customerId)
            ->where('framework_id', $frameworkId)->where('id', $versionId)->first(['status']);
        $target = $this->nodes($customerId, $frameworkId, $versionId, [$nodeId])[$nodeId] ?? null;
        if ($version === null || $target === null) {
            throw new LearningAuthoringBasisException('target_unavailable');
        }

        $relations = [];
        $visited = [$nodeId => true];
        $expanded = [$nodeId => true];
        $queue = [$nodeId];
        while ($queue !== []) {
            $current = array_shift($queue);
            $edges = DB::table('core_learning_node_relations')->where('customer_id', $customerId)
                ->where('framework_id', $frameworkId)->where('owning_framework_version_id', $versionId)
                ->where('relation_scope', 'semantic')
                ->where(fn ($q) => $q->where('source_learning_node_id', $current)->orWhere('target_learning_node_id', $current))
                ->orderBy('id')->get(['id', 'relation_type', 'source_learning_node_id', 'target_learning_node_id']);
            foreach ($edges as $edge) {
                $transitive = in_array($edge->relation_type, ['prerequisite', 'part_of'], true);
                // supports counts only when incident to the target itself.
                if (! $transitive && $current !== $nodeId) {
                    continue;
                }
                $relations[(int) $edge->id] = [
                    'id' => (int) $edge->id, 'type' => $edge->relation_type,
                    'source_node_id' => (int) $edge->source_learning_node_id, 'target_node_id' => (int) $edge->target_learning_node_id,
                ];
                foreach ([(int) $edge->source_learning_node_id, (int) $edge->target_learning_node_id] as $endpoint) {
                    $visited[$endpoint] = true;
                    if (count($visited) > self::MAX_DEPENDENCY_NODES) {
                        throw new LearningAuthoringBasisException('dependency_limit');
                    }
                    // Expansion is tracked apart from inclusion: a Node first
                    // reached through `supports` must still be expanded when a
                    // prerequisite/part_of edge also reaches it.
                    if ($transitive && ! isset($expanded[$endpoint])) {
                        $expanded[$endpoint] = true;
                        $queue[] = $endpoint;
                    }
                }
            }
        }
        ksort($relations);
        $dependencyIds = array_values(array_diff(array_map('intval', array_keys($visited)), [$nodeId]));
        sort($dependencyIds);
        $dependencies = $this->nodes($customerId, $frameworkId, $versionId, $dependencyIds);
        ksort($dependencies);

        $snapshot = [
            'target_schema_version' => self::TARGET_SCHEMA,
            'customer_id' => $customerId,
            'framework_id' => $frameworkId,
            'framework_version_id' => $versionId,
            'node' => $this->content($target),
            'relations' => array_values($relations),
            'dependency_nodes' => array_values(array_map(fn (object $n): array => $this->content($n), $dependencies)),
        ];

        return [
            'snapshot' => $snapshot,
            'target_hash' => CanonicalJson::hash($snapshot),
            'node_status' => (string) $target->status,
            'version_status' => (string) $version->status,
            'definition_id' => (int) $target->node_definition_id,
        ];
    }

    /** @param array<int,int> $ids @return array<int,object> keyed by Node id */
    private function nodes(int $customerId, int $frameworkId, int $versionId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('core_learning_nodes as nodes')
            ->join('core_learning_node_definitions as definitions', function ($join): void {
                $join->on('definitions.id', '=', 'nodes.node_definition_id')->on('definitions.customer_id', '=', 'nodes.customer_id');
            })
            ->where('nodes.customer_id', $customerId)->where('nodes.framework_id', $frameworkId)
            ->where('nodes.framework_version_id', $versionId)->whereIn('nodes.id', $ids)
            ->get(['nodes.id', 'nodes.node_definition_id', 'nodes.code_snapshot', 'nodes.name_snapshot',
                'nodes.description_snapshot', 'nodes.criteria_snapshot', 'nodes.status', 'definitions.node_type'])
            ->keyBy(fn (object $n): int => (int) $n->id)->all();
    }

    /** @return array<string,mixed> */
    private function content(object $node): array
    {
        return [
            'id' => (int) $node->id,
            'definition_id' => (int) $node->node_definition_id,
            'code' => (string) $node->code_snapshot,
            'label' => (string) $node->name_snapshot,
            'description' => $node->description_snapshot,
            'node_type' => (string) $node->node_type,
            'criteria' => $node->criteria_snapshot === null ? null : json_decode((string) $node->criteria_snapshot, true),
        ];
    }

    private function versionContentHash(int $customerId, int $frameworkId, int $versionId): string
    {
        $nodes = DB::table('core_learning_nodes')->where('customer_id', $customerId)
            ->where('framework_id', $frameworkId)->where('framework_version_id', $versionId)
            ->orderBy('id')->get(['id', 'node_definition_id', 'code_snapshot', 'name_snapshot', 'description_snapshot', 'criteria_snapshot', 'sequence', 'status'])
            ->map(fn (object $n): array => (array) $n)->all();

        return CanonicalJson::hash(['framework_id' => $frameworkId, 'framework_version_id' => $versionId, 'nodes' => $nodes]);
    }
}
