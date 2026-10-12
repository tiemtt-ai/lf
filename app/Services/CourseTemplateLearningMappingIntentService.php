<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Course-owned draft intent; all Learning reads remain behind the Learning read service. */
final class CourseTemplateLearningMappingIntentService
{
    public function __construct(private readonly LearningFrameworkReadService $learningReads) {}

    /** @return array{versions: Collection, nodes: Collection, intents: Collection, upgrade: array<string, mixed>|null} */
    public function state(int $actorId, int $customerId, int $templateId): array
    {
        $template = $this->template($customerId, $templateId);
        $versions = $this->learningReads->publishedVersionsWithNodes($actorId);
        $nodes = $template->selected_learning_framework_id && $template->selected_learning_framework_version_id
            ? $this->learningReads->activeNodesForPublishedVersion($actorId, (int) $template->selected_learning_framework_id, (int) $template->selected_learning_framework_version_id)
            : collect();

        $intents = DB::table('core_course_template_learning_mapping_intents')
            ->where('customer_id', $customerId)->where('template_id', $templateId)
            ->orderBy('id')->get();

        // Node labels come from the Learning read service, not a join. G2-N1
        // puts every `core_learning_*` read behind that service, and rendering
        // a Node name is still a read.
        $labels = $this->learningReads->nodeLabels($actorId, $intents->pluck('learning_node_id')->all());

        return [
            'versions' => $versions,
            'nodes' => $nodes,
            'intents' => $intents->map(function (object $intent) use ($labels): object {
                $label = $labels->get((int) $intent->learning_node_id);
                $intent->code_snapshot = $label->code_snapshot ?? null;
                $intent->name_snapshot = $label->name_snapshot ?? null;

                return $intent;
            }),
            'upgrade' => $this->upgradePlan($actorId, $customerId, $templateId),
        ];
    }

    public function select(int $actorId, int $customerId, int $templateId, int $frameworkId, int $versionId): void
    {
        $this->learningReads->assertPublishedVersionForAuthor($actorId, $frameworkId, $versionId);
        DB::transaction(function () use ($customerId, $templateId, $frameworkId, $versionId): void {
            $template = $this->template($customerId, $templateId, true);
            $changed = (int) ($template->selected_learning_framework_id ?? 0) !== $frameworkId
                || (int) ($template->selected_learning_framework_version_id ?? 0) !== $versionId;
            if ($changed && DB::table('core_course_template_learning_mapping_intents')->where('customer_id', $customerId)->where('template_id', $templateId)->exists()) {
                throw ValidationException::withMessages(['framework_version_id' => __('lf.LF_course_template_learning_error_change_locked')]);
            }
            DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $templateId)->update([
                'selected_learning_framework_id' => $frameworkId,
                'selected_learning_framework_version_id' => $versionId,
                'working_revision' => (int) $template->working_revision + 1,
                'updated_at' => now(),
            ]);
        });
    }

    public function store(int $actorId, int $customerId, int $templateId, array $data): void
    {
        DB::transaction(function () use ($actorId, $customerId, $templateId, $data): void {
            $template = $this->template($customerId, $templateId, true);
            $frameworkId = (int) ($template->selected_learning_framework_id ?? 0);
            $versionId = (int) ($template->selected_learning_framework_version_id ?? 0);
            if (! $frameworkId || ! $versionId) {
                throw ValidationException::withMessages(['framework_version_id' => __('lf.LF_course_template_learning_error_select_first')]);
            }
            $this->learningReads->assertPublishedVersionForAuthor($actorId, $frameworkId, $versionId);
            $this->assertSource($customerId, $templateId, $data['source_type'], (int) $data['source_id']);
            $node = DB::table('core_learning_nodes')->where('customer_id', $customerId)->where('id', $data['learning_node_id'])
                ->where('framework_id', $frameworkId)->where('framework_version_id', $versionId)->where('status', 'active')->first();
            if (! $node) {
                throw ValidationException::withMessages(['learning_node_id' => __('lf.LF_course_template_learning_error_node_inactive')]);
            }
            $identity = [
                'customer_id' => $customerId, 'template_id' => $templateId, 'source_type' => $data['source_type'],
                'source_id' => $data['source_id'], 'learning_node_id' => $node->id, 'mapping_role' => $data['mapping_role'],
            ];
            if (DB::table('core_course_template_learning_mapping_intents')->where($identity)->exists()) {
                throw $this->duplicateIdentity();
            }
            $now = now();
            try {
                DB::table('core_course_template_learning_mapping_intents')->insert($identity + [
                    'framework_id' => $frameworkId, 'framework_version_id' => $versionId,
                    'weight' => $data['weight'] ?? null, 'origin' => 'manual',
                    'created_by' => $actorId, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The template row is locked above, so a concurrent writer normally
                // waits. uk_cct_lmi_identity stays the last line of defence: never
                // let it surface as an unhandled 500 on the mapping tab.
                throw $this->duplicateIdentity();
            }
            DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $templateId)
                ->update(['working_revision' => (int) $template->working_revision + 1, 'updated_at' => $now]);
        });
    }

    /**
     * Several Nodes for one source, all or none: the outer transaction rolls every
     * earlier insert back when a later Node is refused.
     *
     * @param  array<int,int>  $nodeIds
     */
    public function storeMany(int $actorId, int $customerId, int $templateId, array $data, array $nodeIds): void
    {
        DB::transaction(function () use ($actorId, $customerId, $templateId, $data, $nodeIds): void {
            foreach (array_values(array_unique(array_map('intval', $nodeIds))) as $nodeId) {
                $this->store($actorId, $customerId, $templateId, ['learning_node_id' => $nodeId] + $data);
            }
        });
    }

    /**
     * What moving to the newest published Version of the selected Framework
     * would do, or null when the Template already uses it. Each Intent keeps
     * its source and role and moves to the target Node of the same stable
     * Definition; one without exactly one such Node would be removed.
     *
     * @return array{version: object, kept: Collection, removed: Collection, ai_blocked: bool}|null
     */
    public function upgradePlan(int $actorId, int $customerId, int $templateId): ?array
    {
        $template = $this->template($customerId, $templateId);
        $frameworkId = (int) ($template->selected_learning_framework_id ?? 0);
        $currentId = (int) ($template->selected_learning_framework_version_id ?? 0);
        if (! $frameworkId || ! $currentId) {
            return null;
        }
        $versions = $this->learningReads->publishedVersionsWithNodes($actorId)->where('framework_id', $frameworkId);
        $current = $versions->firstWhere('framework_version_id', $currentId);
        $target = $versions->sortByDesc('version_number')->first();
        if (! $target || ! $current || (int) $target->version_number <= (int) $current->version_number) {
            return null;
        }

        $intents = DB::table('core_course_template_learning_mapping_intents')
            ->where('customer_id', $customerId)->where('template_id', $templateId)->orderBy('id')->get();
        $oldNodes = $this->learningReads->nodeLabels($actorId, $intents->pluck('learning_node_id')->all());
        $targetByDefinition = $this->learningReads
            ->activeNodesForPublishedVersion($actorId, $frameworkId, (int) $target->framework_version_id)
            ->groupBy('node_definition_id');

        $kept = collect();
        $removed = collect();
        foreach ($intents as $intent) {
            $old = $oldNodes->get((int) $intent->learning_node_id);
            $intent->name_snapshot = $old->name_snapshot ?? null;
            $matches = $old ? $targetByDefinition->get((int) $old->node_definition_id, collect()) : collect();
            if ($matches->count() === 1) {
                $intent->target_node_id = (int) $matches->first()->id;
                $kept->push($intent);
            } else {
                $removed->push($intent);
            }
        }

        return [
            'version' => $target,
            'kept' => $kept,
            'removed' => $removed,
            'ai_blocked' => $intents->contains(fn (object $intent): bool => $intent->origin !== 'manual'),
        ];
    }

    /**
     * Moves the Template to the planned Version in one transaction, following
     * the reviewed rebase order (CourseAuthoringRebaseService): the selection
     * FK is immediate, so the old Intents are removed, the selection moves and
     * the kept Intents are inserted again on their target Nodes. The plan is
     * recomputed under the Template lock and must still name the Version and
     * working revision the admin confirmed. AI-origin Intents need their own
     * reviewed rebase and block this manual path.
     */
    public function upgrade(int $actorId, int $customerId, int $templateId, int $targetVersionId, int $expectedRevision): int
    {
        return DB::transaction(function () use ($actorId, $customerId, $templateId, $targetVersionId, $expectedRevision): int {
            $template = $this->template($customerId, $templateId, true);
            DB::table('core_course_template_learning_mapping_intents')
                ->where('customer_id', $customerId)->where('template_id', $templateId)->lockForUpdate()->get();
            $plan = $this->upgradePlan($actorId, $customerId, $templateId);
            if ($plan === null || (int) $plan['version']->framework_version_id !== $targetVersionId
                || (int) $template->working_revision !== $expectedRevision) {
                throw ValidationException::withMessages(['framework_version_id' => __('lf.LF_course_template_learning_error_upgrade_stale')]);
            }
            if ($plan['ai_blocked']) {
                throw ValidationException::withMessages(['framework_version_id' => __('lf.LF_course_template_learning_error_upgrade_ai')]);
            }

            $frameworkId = (int) $template->selected_learning_framework_id;
            DB::table('core_course_template_learning_mapping_intents')
                ->where('customer_id', $customerId)->where('template_id', $templateId)->delete();
            $now = now();
            DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $templateId)->update([
                'selected_learning_framework_version_id' => $targetVersionId,
                'working_revision' => (int) $template->working_revision + 1,
                'updated_at' => $now,
            ]);

            $inserted = [];
            foreach ($plan['kept'] as $intent) {
                $identity = $intent->source_type.':'.$intent->source_id.':'.$intent->target_node_id.':'.$intent->mapping_role;
                if (isset($inserted[$identity])) {
                    continue;
                }
                $inserted[$identity] = true;
                DB::table('core_course_template_learning_mapping_intents')->insert([
                    'customer_id' => $customerId, 'template_id' => $templateId,
                    'source_type' => $intent->source_type, 'source_id' => $intent->source_id,
                    'framework_id' => $frameworkId, 'framework_version_id' => $targetVersionId,
                    'learning_node_id' => $intent->target_node_id, 'mapping_role' => $intent->mapping_role,
                    'weight' => $intent->weight, 'origin' => 'manual',
                    'created_by' => $intent->created_by, 'updated_by' => $actorId,
                    'created_at' => $intent->created_at, 'updated_at' => $now,
                ]);
            }

            return count($inserted);
        });
    }

    /** uk_cct_lmi_identity ignores weight and framework columns; identity is source + Node + role. */
    private function duplicateIdentity(): ValidationException
    {
        return ValidationException::withMessages([
            'learning_node_id' => __('lf.LF_course_template_learning_error_duplicate'),
        ]);
    }

    public function destroy(int $customerId, int $templateId, int $intentId): void
    {
        DB::transaction(function () use ($customerId, $templateId, $intentId): void {
            $template = $this->template($customerId, $templateId, true);
            if (DB::table('core_course_template_learning_mapping_intents')->where('customer_id', $customerId)->where('template_id', $templateId)->where('id', $intentId)->delete()) {
                DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $templateId)
                    ->update(['working_revision' => (int) $template->working_revision + 1, 'updated_at' => now()]);
            }
        });
    }

    private function template(int $customerId, int $templateId, bool $lock = false): object
    {
        $row = DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $templateId)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_if(! $row, 404);

        return $row;
    }

    private function assertSource(int $customerId, int $templateId, string $type, int $id): void
    {
        $table = $type === 'course_template_lesson' ? 'core_course_template_lessons' : ($type === 'course_template_activity' ? 'core_course_template_activities' : null);
        if (! $table || ! DB::table($table)->where('customer_id', $customerId)->where('template_id', $templateId)->where('id', $id)->exists()) {
            throw ValidationException::withMessages(['source_id' => __('lf.LF_course_template_learning_error_source')]);
        }
    }
}
