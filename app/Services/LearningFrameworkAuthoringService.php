<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Internal authoring path for the Learning Foundation graph.
 *
 * Teacher Judgment cannot run without a versioned Node to judge against, and
 * nothing else in the repository creates one. This service closes that gap for
 * internal callers only: it exposes no route, controller or HTTP surface, so it
 * does not touch the external-surface authorization gate.
 *
 * The database already enforces the hard parts — ordered mastery scales,
 * draft-only version inserts, the one-way version lifecycle, and node
 * immutability once the parent version leaves draft. Two rules have no database
 * backstop and are enforced here:
 *
 *   - a Node may only be added while its Framework Version is `draft_snapshot`.
 *     `core_learning_nodes` has no before-insert trigger, so the engine would
 *     otherwise accept a Node inserted into an already published version and
 *     silently break the "graph is frozen at publish" contract.
 *   - a Framework must still be `active` to receive new versions or
 *     definitions. Nothing physical prevents authoring into an archived
 *     Framework.
 *
 * Open policy question, deliberately not decided here: which roles may author a
 * Framework. The service requires an active user in the tenant and nothing
 * more, because no Owner decision names the authoring role. Callers must not
 * read that silence as permission.
 */
final class LearningFrameworkAuthoringService
{
    private const NODE_TYPES = ['objective', 'concept', 'competency'];

    public function __construct(private readonly LearningRuntimeAccess $access) {}

    /**
     * @param  array<string, mixed>  $command
     */
    public function createFramework(int $actorId, array $command): object
    {
        $customerId = $this->access->tenantId();
        $scale = $this->normalizeScale($command['mastery_scale'] ?? null);

        return DB::transaction(function () use ($customerId, $actorId, $command, $scale): object {
            $this->assertActor($customerId, $actorId);
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
    public function createDraftVersion(int $actorId, array $command): object
    {
        $customerId = $this->access->tenantId();

        return DB::transaction(function () use ($customerId, $actorId, $command): object {
            $this->assertActor($customerId, $actorId);
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
    public function createDefinition(int $actorId, array $command): object
    {
        $customerId = $this->access->tenantId();
        $type = (string) ($command['node_type'] ?? '');
        if (! in_array($type, self::NODE_TYPES, true)) {
            throw new DomainException('LF_FRAMEWORK_AUTHORING_NODE_TYPE_INVALID');
        }

        return DB::transaction(function () use ($customerId, $actorId, $command, $type): object {
            $this->assertActor($customerId, $actorId);
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
    public function createNode(int $actorId, array $command): object
    {
        $customerId = $this->access->tenantId();

        return DB::transaction(function () use ($customerId, $actorId, $command): object {
            $this->assertActor($customerId, $actorId);
            $version = $this->lockRow(
                'core_learning_framework_versions', $customerId, (int) ($command['framework_version_id'] ?? 0)
            );
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

    public function publishVersion(int $actorId, int $versionId): object
    {
        $customerId = $this->access->tenantId();

        return DB::transaction(function () use ($customerId, $actorId, $versionId): object {
            $this->assertActor($customerId, $actorId);
            $version = $this->lockRow('core_learning_framework_versions', $customerId, $versionId);

            if ($version->status !== 'draft_snapshot') {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT');
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

    private function assertActor(int $customerId, int $actorId): void
    {
        $actor = DB::table('users')->where('customer_id', $customerId)->where('id', $actorId)->first();

        if ($actor === null || $actor->status !== 'active') {
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
        foreach ($levels as $level) {
            $key = is_array($level) ? trim((string) ($level['key'] ?? '')) : '';
            $threshold = is_array($level) && is_numeric($level['threshold'] ?? null)
                ? (float) $level['threshold'] : null;

            if ($key === '' || $threshold === null || isset($seen[$key])
                || ($previous !== null && $threshold <= $previous)) {
                throw new DomainException('LF_FRAMEWORK_AUTHORING_SCALE_INVALID');
            }

            $seen[$key] = true;
            $previous = $threshold;
        }

        return json_encode($scale, JSON_THROW_ON_ERROR);
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

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }
}
