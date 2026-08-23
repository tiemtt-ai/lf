<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Owner boundary for authoring the Learning Foundation graph.
 *
 * Teacher Judgment cannot run without a versioned Node to judge against, and
 * nothing else may create one. Internal callers and the customer-admin manual
 * authoring surface both use this service so authorization, tenant ownership
 * and lifecycle policy cannot be bypassed by the transport layer.
 *
 * The database already enforces the hard parts — ordered mastery scales,
 * draft-only version inserts, the one-way version lifecycle, and node
 * immutability once the parent version leaves draft. Four rules have no database
 * backstop and are enforced here:
 *
 *   - a Node may only be added while its Framework Version is `draft_snapshot`.
 *     `core_learning_nodes` has no before-insert trigger, so the engine would
 *     otherwise accept a Node inserted into an already published version and
 *     silently break the "graph is frozen at publish" contract.
 *   - a Framework must still be `active` to receive new versions or
 *     definitions. Nothing physical prevents authoring into an archived
 *     Framework.
 *   - the semantic identity of a Definition becomes immutable once a
 *     non-draft version references it. The database freezes versioned Node
 *     snapshots but does not protect the stable Definition they point to.
 *   - a Version needs at least one active Node before publication, otherwise
 *     it could become a permanent but semantically empty judgment basis.
 *
 * Authoring and publishing are restricted to `customer_admin` by Owner decision
 * N1 of 2026-08-17, taken through a capability argument so the two can separate
 * later without reopening every method. See assertActor().
 */
final class LearningFrameworkAuthoringService
{
    private const NODE_TYPES = ['objective', 'concept', 'competency'];

    private const CAPABILITY_AUTHOR = 'author';

    private const CAPABILITY_PUBLISH = 'publish';

    public function __construct(private readonly LearningRuntimeAccess $access) {}

    /**
     * @param  array<string, mixed>  $command
     */
    public function createFramework(int $actorId, array $command): object
    {
        $customerId = $this->access->tenantId();
        $scale = $this->normalizeScale($command['mastery_scale'] ?? null);

        return DB::transaction(function () use ($customerId, $actorId, $command, $scale): object {
            $this->assertActor($customerId, $actorId, self::CAPABILITY_AUTHOR);
            $now = $this->now();

            $id = DB::table('core_learning_frameworks')->insertGetId([
                'customer_id' => $customerId,
                'code' => $this->text($command, 'code', 100),
                'name' => $this->text($command, 'name', 255),
                'description' => $this->optionalText($command, 'description'),
                'default_mastery_scale_key' => $this->text($command, 'mastery_scale_key', 100),
                'default_mastery_scale_version' => $this->text($command, 'mastery_scale_version', 50),
                'default_mastery_scale' => $scale,
                'status' => 'active',
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->row('core_learning_frameworks', $customerId, $id);
        });
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function updateFramework(int $actorId, int $frameworkId, array $command): object
    {
        $customerId = $this->access->tenantId();
        $scale = $this->normalizeScale($command['mastery_scale'] ?? null);

        return DB::transaction(function () use ($customerId, $actorId, $frameworkId, $command, $scale): object {
            $this->assertActor($customerId, $actorId, self::CAPABILITY_AUTHOR);
            $framework = $this->lockFramework($customerId, $frameworkId);

            DB::table('core_learning_frameworks')
                ->where('customer_id', $customerId)
                ->where('id', $framework->id)
                ->update([
                    'code' => $this->text($command, 'code', 100),
                    'name' => $this->text($command, 'name', 255),
                    'description' => $this->optionalText($command, 'description'),
                    'default_mastery_scale_key' => $this->text($command, 'mastery_scale_key', 100),
                    'default_mastery_scale_version' => $this->text($command, 'mastery_scale_version', 50),
                    'default_mastery_scale' => $scale,
                    'updated_by' => $actorId,
                    'updated_at' => $this->now(),
                ]);

            return $this->row('core_learning_frameworks', $customerId, $framework->id);
        });
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function createDraftVersion(int $actorId, array $command): object
    {
        $customerId = $this->access->tenantId();

        return DB::transaction(function () use ($customerId, $actorId, $command): object {
            $this->assertActor($customerId, $actorId, self::CAPABILITY_AUTHOR);
            $framework = $this->lockFramework($customerId, (int) ($command['framework_id'] ?? 0));
            $now = $this->now();

            // Derived rather than caller-supplied: the version number only has
            // to be unique and increasing within the Framework, and letting a
            // caller pick it invites collisions on idx_lrn_004.
            $next = 1 + (int) DB::table('core_learning_framework_versions')
                ->where('customer_id', $customerId)
                ->where('framework_id', $framework->id)
                ->max('version_number');

            $id = DB::table('core_learning_framework_versions')->insertGetId([
                'customer_id' => $customerId,
                'framework_id' => $framework->id,
                'version_number' => $next,
                'version_code' => $this->text($command, 'version_code', 100),
                'title_snapshot' => $this->text($command, 'title', 255),
                'description_snapshot' => $this->optionalText($command, 'description'),
                'mastery_scale_key' => $framework->default_mastery_scale_key,
                'mastery_scale_version' => $framework->default_mastery_scale_version,
                'mastery_scale_snapshot' => $framework->default_mastery_scale,
                'status' => 'draft_snapshot',
                'published_at' => null,
                'published_by' => null,
                'deprecated_at' => null,
                'deprecated_by' => null,
                'archived_at' => null,
                'archived_by' => null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->row('core_learning_framework_versions', $customerId, $id);
        });
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function updateDraftVersion(int $actorId, int $versionId, array $command): object
    {
        $customerId = $this->access->tenantId();

        return DB::transaction(function () use ($customerId, $actorId, $versionId, $command): object {
            $this->assertActor($customerId, $actorId, self::CAPABILITY_AUTHOR);
            $version = $this->lockRow('core_learning_framework_versions', $customerId, $versionId);
            $this->lockFramework($customerId, (int) $version->framework_id);

            if ($version->status !== 'draft_snapshot') {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT');
            }
            if ((int) ($command['framework_id'] ?? 0) !== (int) $version->framework_id) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_FRAMEWORK_MISMATCH');
            }
            if ($this->text($command, 'version_code', 100) !== $version->version_code) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_VERSION_CODE_IMMUTABLE');
            }

            DB::table('core_learning_framework_versions')
                ->where('customer_id', $customerId)
                ->where('id', $version->id)
                ->update([
                    'title_snapshot' => $this->text($command, 'title', 255),
                    'description_snapshot' => $this->optionalText($command, 'description'),
                    'updated_by' => $actorId,
                    'updated_at' => $this->now(),
                ]);

            return $this->row('core_learning_framework_versions', $customerId, $version->id);
        });
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function createDefinition(int $actorId, array $command): object
    {
        $customerId = $this->access->tenantId();
        $type = (string) ($command['node_type'] ?? '');
        if (! in_array($type, self::NODE_TYPES, true)) {
            throw new DomainException('LF_FRAMEWORK_AUTHORING_NODE_TYPE_INVALID');
        }

        return DB::transaction(function () use ($customerId, $actorId, $command, $type): object {
            $this->assertActor($customerId, $actorId, self::CAPABILITY_AUTHOR);
            $framework = $this->lockFramework($customerId, (int) ($command['framework_id'] ?? 0));
            $now = $this->now();

            $id = DB::table('core_learning_node_definitions')->insertGetId([
                'customer_id' => $customerId,
                'framework_id' => $framework->id,
                'code' => $this->text($command, 'code', 120),
                'node_type' => $type,
                'canonical_name' => $this->text($command, 'canonical_name', 255),
                'description' => $this->optionalText($command, 'description'),
                'status' => 'active',
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->row('core_learning_node_definitions', $customerId, $id);
        });
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function updateDefinition(int $actorId, int $definitionId, array $command): object
    {
        $customerId = $this->access->tenantId();
        $type = (string) ($command['node_type'] ?? '');
        if (! in_array($type, self::NODE_TYPES, true)) {
            throw new DomainException('LF_FRAMEWORK_AUTHORING_NODE_TYPE_INVALID');
        }

        return DB::transaction(function () use ($customerId, $actorId, $definitionId, $command, $type): object {
            $this->assertActor($customerId, $actorId, self::CAPABILITY_AUTHOR);
            $definition = $this->lockRow('core_learning_node_definitions', $customerId, $definitionId);
            $this->lockFramework($customerId, (int) $definition->framework_id);

            if ((int) ($command['framework_id'] ?? 0) !== (int) $definition->framework_id) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_FRAMEWORK_MISMATCH');
            }
            if ($definition->status !== 'active') {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_DEFINITION_INACTIVE');
            }

            $hasPublishedUse = DB::table('core_learning_nodes as nodes')
                ->join('core_learning_framework_versions as versions', function ($join): void {
                    $join->on('versions.id', '=', 'nodes.framework_version_id')
                        ->on('versions.customer_id', '=', 'nodes.customer_id');
                })
                ->where('nodes.customer_id', $customerId)
                ->where('nodes.node_definition_id', $definition->id)
                ->where('versions.status', '<>', 'draft_snapshot')
                ->exists();

            if ($hasPublishedUse && (
                $this->text($command, 'code', 120) !== $definition->code
                || $type !== $definition->node_type
                || $this->text($command, 'canonical_name', 255) !== $definition->canonical_name
            )) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_DEFINITION_IDENTITY_IMMUTABLE');
            }

            DB::table('core_learning_node_definitions')
                ->where('customer_id', $customerId)
                ->where('id', $definition->id)
                ->update([
                    'code' => $this->text($command, 'code', 120),
                    'node_type' => $type,
                    'canonical_name' => $this->text($command, 'canonical_name', 255),
                    'description' => $this->optionalText($command, 'description'),
                    'updated_by' => $actorId,
                    'updated_at' => $this->now(),
                ]);

            // Draft snapshots track their stable Definition until publish.
            DB::table('core_learning_nodes as nodes')
                ->join('core_learning_framework_versions as versions', function ($join): void {
                    $join->on('versions.id', '=', 'nodes.framework_version_id')
                        ->on('versions.customer_id', '=', 'nodes.customer_id');
                })
                ->where('nodes.customer_id', $customerId)
                ->where('nodes.node_definition_id', $definition->id)
                ->where('versions.status', 'draft_snapshot')
                ->update([
                    'nodes.code_snapshot' => $this->text($command, 'code', 120),
                    'nodes.name_snapshot' => $this->text($command, 'canonical_name', 255),
                    'nodes.description_snapshot' => $this->optionalText($command, 'description'),
                    'nodes.updated_by' => $actorId,
                    'nodes.updated_at' => $this->now(),
                ]);

            return $this->row('core_learning_node_definitions', $customerId, $definition->id);
        });
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function createNode(int $actorId, array $command): object
    {
        $customerId = $this->access->tenantId();

        return DB::transaction(function () use ($customerId, $actorId, $command): object {
            $this->assertActor($customerId, $actorId, self::CAPABILITY_AUTHOR);
            $version = $this->lockRow(
                'core_learning_framework_versions', $customerId, (int) ($command['framework_version_id'] ?? 0)
            );
            $this->lockFramework($customerId, (int) $version->framework_id);
            $definition = $this->lockRow(
                'core_learning_node_definitions', $customerId, (int) ($command['node_definition_id'] ?? 0)
            );

            if ($version->status !== 'draft_snapshot') {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT');
            }
            if ((int) $definition->framework_id !== (int) $version->framework_id) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_FRAMEWORK_MISMATCH');
            }
            if ($definition->status !== 'active') {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_DEFINITION_INACTIVE');
            }

            $now = $this->now();
            $sequence = array_key_exists('sequence', $command)
                ? (int) $command['sequence']
                : 1 + (int) DB::table('core_learning_nodes')
                    ->where('customer_id', $customerId)
                    ->where('framework_version_id', $version->id)
                    ->max('sequence');

            $id = DB::table('core_learning_nodes')->insertGetId([
                'customer_id' => $customerId,
                'framework_id' => $version->framework_id,
                'framework_version_id' => $version->id,
                'node_definition_id' => $definition->id,
                'code_snapshot' => $definition->code,
                'name_snapshot' => $definition->canonical_name,
                'description_snapshot' => $definition->description,
                'criteria_snapshot' => $this->optionalJson($command['criteria'] ?? null),
                'sequence' => $sequence,
                'status' => 'active',
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->row('core_learning_nodes', $customerId, $id);
        });
    }

    /**
     * Refreshes a draft Node snapshot from its current stable Definition while
     * applying draft-only criteria and ordering changes.
     *
     * @param  array<string, mixed>  $command
     */
    public function updateDraftNode(int $actorId, int $nodeId, array $command): object
    {
        $customerId = $this->access->tenantId();

        return DB::transaction(function () use ($customerId, $actorId, $nodeId, $command): object {
            $this->assertActor($customerId, $actorId, self::CAPABILITY_AUTHOR);
            $node = $this->lockRow('core_learning_nodes', $customerId, $nodeId);
            $version = $this->lockRow('core_learning_framework_versions', $customerId, (int) $node->framework_version_id);
            $this->lockFramework($customerId, (int) $version->framework_id);
            $definition = $this->lockRow('core_learning_node_definitions', $customerId, (int) $node->node_definition_id);

            if ($version->status !== 'draft_snapshot') {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT');
            }
            if ((int) ($command['framework_version_id'] ?? 0) !== (int) $version->id
                || (int) $definition->framework_id !== (int) $version->framework_id) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_FRAMEWORK_MISMATCH');
            }
            if ((int) ($command['node_definition_id'] ?? 0) !== (int) $node->node_definition_id) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_NODE_DEFINITION_IMMUTABLE');
            }
            if ($definition->status !== 'active') {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_DEFINITION_INACTIVE');
            }

            DB::table('core_learning_nodes')
                ->where('customer_id', $customerId)
                ->where('id', $node->id)
                ->update([
                    'code_snapshot' => $definition->code,
                    'name_snapshot' => $definition->canonical_name,
                    'description_snapshot' => $definition->description,
                    'criteria_snapshot' => $this->optionalJson($command['criteria'] ?? null),
                    'sequence' => (int) ($command['sequence'] ?? $node->sequence),
                    'updated_by' => $actorId,
                    'updated_at' => $this->now(),
                ]);

            return $this->row('core_learning_nodes', $customerId, $node->id);
        });
    }

    public function publishVersion(int $actorId, int $versionId): object
    {
        $customerId = $this->access->tenantId();

        return DB::transaction(function () use ($customerId, $actorId, $versionId): object {
            $this->assertActor($customerId, $actorId, self::CAPABILITY_PUBLISH);
            $version = $this->lockRow('core_learning_framework_versions', $customerId, $versionId);
            // N2: the Framework guard reached only two of the four write paths.
            // Nothing physical checks Framework status on publish —
            // trg_lrn_fw_versions_bu_immutable validates the version's own
            // lifecycle only — so archiving a Framework did not stop its draft
            // version becoming a permanently valid judgment basis.
            $this->lockFramework($customerId, (int) $version->framework_id);

            if ($version->status !== 'draft_snapshot') {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT');
            }
            if (! DB::table('core_learning_nodes')
                ->where('customer_id', $customerId)
                ->where('framework_version_id', $version->id)
                ->where('status', 'active')
                ->exists()) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_VERSION_EMPTY');
            }

            $now = $this->now();
            DB::table('core_learning_framework_versions')
                ->where('id', $version->id)
                ->where('customer_id', $customerId)
                ->update([
                    'status' => 'published',
                    'published_at' => $now,
                    'published_by' => $actorId,
                    'updated_by' => $actorId,
                    'updated_at' => $now,
                ]);

            return $this->row('core_learning_framework_versions', $customerId, $version->id);
        });
    }

    /**
     * Owner decision N1, 2026-08-17. Both capabilities map to `customer_admin`
     * today and no authoring role is created.
     *
     * Publishing a Framework Version is curriculum authority, not teaching: it
     * defines the measure every learner in the tenant is judged against, it is
     * one-way, and it becomes a valid basis for permanent Evidence the moment it
     * lands. Owner decision 4 already bars `customer_admin` from judging, so
     * granting authoring there yields the separation for free — teachers judge,
     * admins set the measure, nobody does both.
     *
     * The capability is a parameter even though both values resolve alike. The
     * likely end state is an author drafting and an admin publishing; folding
     * them into one check now would mean reopening every method on the day they
     * separate, and widening a role set later is additive while narrowing it is
     * not.
     */
    private function assertActor(int $customerId, int $actorId, string $capability): void
    {
        if (! in_array($capability, [self::CAPABILITY_AUTHOR, self::CAPABILITY_PUBLISH], true)) {
            throw new DomainException('LF_FRAMEWORK_AUTHORING_CAPABILITY_UNKNOWN');
        }

        $actor = DB::table('users')->where('customer_id', $customerId)->where('id', $actorId)->first();

        if ($actor === null || $actor->status !== 'active' || $actor->role !== 'customer_admin') {
            throw new DomainException('LF_FRAMEWORK_AUTHORING_ACTOR_DENIED');
        }
    }

    private function lockFramework(int $customerId, int $frameworkId): object
    {
        $framework = $this->lockRow('core_learning_frameworks', $customerId, $frameworkId);

        if ($framework->status !== 'active') {
            throw new DomainException('LF_FRAMEWORK_AUTHORING_FRAMEWORK_ARCHIVED');
        }

        return $framework;
    }

    private function lockRow(string $table, int $customerId, int $id): object
    {
        $row = DB::table($table)->where('customer_id', $customerId)->where('id', $id)
            ->lockForUpdate()->first();

        if ($row === null) {
            throw new RecordsNotFoundException("{$table} row not found in tenant.");
        }

        return $row;
    }

    private function row(string $table, int $customerId, int $id): object
    {
        return DB::table($table)->where('customer_id', $customerId)->where('id', $id)->firstOrFail();
    }

    /**
     * Validated here as well as by trg_lrn_frameworks_bi_scale so a caller gets
     * a domain error instead of a raw SIGNAL, and so the same rules apply to
     * every scale this service writes.
     */
    private function normalizeScale(mixed $scale): string
    {
        $levels = is_array($scale) ? ($scale['levels'] ?? null) : null;
        if (! is_array($levels) || count($levels) < 2) {
            throw new DomainException('LF_FRAMEWORK_AUTHORING_SCALE_INVALID');
        }

        $seen = [];
        $previous = null;
        $normalizedLevels = [];
        foreach ($levels as $level) {
            $key = is_array($level) ? trim((string) ($level['key'] ?? '')) : '';
            $threshold = is_array($level) && is_numeric($level['threshold'] ?? null)
                ? (float) $level['threshold'] : null;

            // N3: authoring and judging must share one value domain. A judgment
            // score is bounded to [0, 1] here and by chk_ltj_001, so a Framework
            // published on a 0/50/80 scale was accepted and then made every
            // scored judgment against it fail RESULT_INVALID — a defect visible
            // only at judging time, in the Framework rather than the judgment.
            if ($key === '' || $threshold === null || isset($seen[$key])
                || $threshold < 0 || $threshold > 1
                || ($previous !== null && $threshold <= $previous)) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_SCALE_INVALID');
            }

            // The lowest level must start at zero, or a score below it selects
            // no level and the judgment fails however valid the level key is.
            if ($previous === null && $threshold !== 0.0) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_SCALE_INVALID');
            }

            $seen[$key] = true;
            $previous = $threshold;
            $normalizedLevels[] = [
                'key' => $key,
                'threshold' => $threshold,
            ];
        }

        // HTTP form inputs are strings even for <input type="number">. Persist
        // the validated scalar values, not the browser-shaped request payload:
        // Foundation triggers require JSON numeric thresholds.
        return json_encode(['levels' => $normalizedLevels], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function text(array $command, string $key, int $max): string
    {
        $value = trim((string) ($command[$key] ?? ''));
        if ($value === '' || mb_strlen($value) > $max) {
            throw new DomainException('LF_FRAMEWORK_AUTHORING_FIELD_INVALID:'.$key);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function optionalText(array $command, string $key): ?string
    {
        $value = trim((string) ($command[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private function optionalJson(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Application timezone, not UTC: every other writer in LearnForge stores
     * naive wall-clock, and a single table speaking a different convention
     * cannot be reconciled later because the row does not record which one
     * produced it.
     */
    private function now(): string
    {
        return CarbonImmutable::now()->format('Y-m-d H:i:s.u');
    }
}
