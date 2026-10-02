<?php

namespace App\Services;

use App\Exceptions\LearningAuthoringBasisException;
use App\Support\Ai\CanonicalJson;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Learning owner port for Step 7: proposalBasis (contract § Owner-service ports).
 *
 * Learning alone reads `core_learning_*` and decides what a Framework basis is.
 * The caller must already hold Course authority for the Template whose
 * selection this basis is; this port adds no teacher privilege over Learning —
 * it only exposes the active Nodes of an exact published Version, which is what
 * the Template already references.
 *
 * content_hash covers authoring content (Framework/Version identity and text,
 * ordered active Nodes with Definition, snapshot text and criteria) and
 * deliberately excludes published_at/updated_at/status, so a pure publish of
 * unchanged content keeps the hash.
 */
final class LearningAuthoringBasisService
{
    public const SCHEMA = 'learning-authoring-v1';

    /** Model input bound; a larger Version fails closed rather than truncating. */
    public const MAX_CANDIDATES = 500;

    /**
     * @return array{basis_schema_version:string,customer_id:int,framework_id:int,framework_version_id:int,content_hash:string,candidates:array<int,array<string,mixed>>}
     */
    /** Longest description a reviewer is shown; the rest stays in Learning. */
    public const DISPLAY_DESCRIPTION_MAX = 500;

    /**
     * What a reviewer needs to recognise one existing Node (P3-B, contract "Owner
     * amendment — P3-B"): display text only, never criteria, relations or status
     * history. Returns null unless the exact stored pair is an active Node of a
     * published Version of an active Framework whose Definition is the one stored
     * on the proposal, so a stale or tampered identifier shows nothing rather than
     * a name that belongs to something else.
     *
     * Like proposalBasis it adds no Learning privilege of its own: the caller has
     * already proved current AI authority over the Template.
     *
     * @return array{node_id:int,definition_id:int,code:string,label:string,node_type:string,description:?string,status:string}|null
     */
    public function nodeDisplay(int $frameworkId, int $versionId, int $nodeId, int $definitionId): ?array
    {
        $customerId = TenantContext::customerId() ?? throw new LearningAuthoringBasisException('basis_unavailable');

        $node = DB::table('core_learning_nodes as nodes')
            ->join('core_learning_node_definitions as definitions', function ($join): void {
                $join->on('definitions.id', '=', 'nodes.node_definition_id')
                    ->on('definitions.customer_id', '=', 'nodes.customer_id');
            })
            ->join('core_learning_framework_versions as versions', function ($join): void {
                $join->on('versions.id', '=', 'nodes.framework_version_id')
                    ->on('versions.customer_id', '=', 'nodes.customer_id');
            })
            ->join('core_learning_frameworks as frameworks', function ($join): void {
                $join->on('frameworks.id', '=', 'nodes.framework_id')
                    ->on('frameworks.customer_id', '=', 'nodes.customer_id');
            })
            ->where('nodes.customer_id', $customerId)->where('nodes.framework_id', $frameworkId)
            ->where('nodes.framework_version_id', $versionId)->where('nodes.id', $nodeId)
            ->where('nodes.node_definition_id', $definitionId)->where('nodes.status', 'active')
            ->where('versions.status', 'published')->where('frameworks.status', 'active')
            ->first(['nodes.id', 'nodes.node_definition_id', 'nodes.code_snapshot', 'nodes.name_snapshot',
                'nodes.description_snapshot', 'definitions.node_type']);
        if ($node === null) {
            return null;
        }

        return [
            'node_id' => (int) $node->id,
            'definition_id' => (int) $node->node_definition_id,
            'code' => (string) $node->code_snapshot,
            'label' => (string) $node->name_snapshot,
            'node_type' => (string) $node->node_type,
            'description' => $node->description_snapshot === null ? null : mb_substr((string) $node->description_snapshot, 0, self::DISPLAY_DESCRIPTION_MAX),
            'status' => 'active',
        ];
    }

    public function proposalBasis(int $frameworkId, int $versionId): array
    {
        $customerId = TenantContext::customerId() ?? throw new LearningAuthoringBasisException('basis_unavailable');

        $version = DB::table('core_learning_framework_versions as versions')
            ->join('core_learning_frameworks as frameworks', function ($join): void {
                $join->on('frameworks.id', '=', 'versions.framework_id')
                    ->on('frameworks.customer_id', '=', 'versions.customer_id');
            })
            ->where('versions.customer_id', $customerId)->where('versions.framework_id', $frameworkId)
            ->where('versions.id', $versionId)->where('versions.status', 'published')
            ->where('frameworks.status', 'active')
            ->first([
                'frameworks.id as framework_id', 'frameworks.code as framework_code', 'frameworks.name as framework_name',
                'frameworks.description as framework_description', 'versions.id as version_id',
                'versions.version_code', 'versions.title_snapshot', 'versions.description_snapshot',
                'versions.mastery_scale_key', 'versions.mastery_scale_version',
            ]);
        if ($version === null) {
            throw new LearningAuthoringBasisException('basis_unavailable');
        }

        $nodes = DB::table('core_learning_nodes as nodes')
            ->join('core_learning_node_definitions as definitions', function ($join): void {
                $join->on('definitions.id', '=', 'nodes.node_definition_id')
                    ->on('definitions.customer_id', '=', 'nodes.customer_id');
            })
            ->where('nodes.customer_id', $customerId)->where('nodes.framework_id', $frameworkId)
            ->where('nodes.framework_version_id', $versionId)->where('nodes.status', 'active')
            ->orderBy('nodes.id')
            ->limit(self::MAX_CANDIDATES + 1)
            ->get([
                'nodes.id as node_id', 'nodes.node_definition_id as definition_id', 'nodes.code_snapshot',
                'nodes.name_snapshot', 'nodes.description_snapshot', 'nodes.criteria_snapshot', 'nodes.sequence',
                'definitions.node_type', 'definitions.status as definition_status',
            ]);
        if ($nodes->count() > self::MAX_CANDIDATES) {
            throw new LearningAuthoringBasisException('basis_too_large');
        }

        $candidates = $nodes->map(fn (object $node): array => [
            'node_id' => (int) $node->node_id,
            'definition_id' => (int) $node->definition_id,
            'framework_version_id' => $versionId,
            'code' => (string) $node->code_snapshot,
            'label' => (string) $node->name_snapshot,
            'description' => $node->description_snapshot,
            'node_type' => (string) $node->node_type,
            'criteria' => $node->criteria_snapshot === null ? null : json_decode((string) $node->criteria_snapshot, true),
            'sequence' => (int) $node->sequence,
            'status' => 'active',
            'definition_status' => (string) $node->definition_status,
        ])->values()->all();

        $contentHash = CanonicalJson::hash([
            'basis_schema_version' => self::SCHEMA,
            'framework' => [
                'id' => (int) $version->framework_id, 'code' => (string) $version->framework_code,
                'name' => (string) $version->framework_name, 'description' => $version->framework_description,
            ],
            'version' => [
                'id' => (int) $version->version_id, 'version_code' => (string) $version->version_code,
                'title' => (string) $version->title_snapshot, 'description' => $version->description_snapshot,
                'mastery_scale_key' => (string) $version->mastery_scale_key,
                'mastery_scale_version' => (string) $version->mastery_scale_version,
            ],
            'nodes' => $candidates,
        ]);

        return [
            'basis_schema_version' => self::SCHEMA,
            'customer_id' => $customerId,
            'framework_id' => $frameworkId,
            'framework_version_id' => $versionId,
            'content_hash' => $contentHash,
            'candidates' => $candidates,
        ];
    }
}
