<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Course-owned draft intent; all Learning reads remain behind the Learning read service. */
final class CourseTemplateLearningMappingIntentService
{
    public function __construct(private readonly LearningFrameworkReadService $learningReads) {}

    /** @return array{versions: Collection, nodes: Collection, intents: Collection} */
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
                throw ValidationException::withMessages(['framework_version_id' => 'Remove existing Node mappings before changing the selected Framework Version.']);
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
                throw ValidationException::withMessages(['framework_version_id' => 'Select a published Framework Version before adding a Node mapping.']);
            }
            $this->learningReads->assertPublishedVersionForAuthor($actorId, $frameworkId, $versionId);
            $this->assertSource($customerId, $templateId, $data['source_type'], (int) $data['source_id']);
            $node = DB::table('core_learning_nodes')->where('customer_id', $customerId)->where('id', $data['learning_node_id'])
                ->where('framework_id', $frameworkId)->where('framework_version_id', $versionId)->where('status', 'active')->first();
            if (! $node) {
                throw ValidationException::withMessages(['learning_node_id' => 'The selected Node is not active in the selected Framework Version.']);
            }
            $now = now();
            DB::table('core_course_template_learning_mapping_intents')->insert([
                'customer_id' => $customerId, 'template_id' => $templateId, 'source_type' => $data['source_type'], 'source_id' => $data['source_id'],
                'framework_id' => $frameworkId, 'framework_version_id' => $versionId, 'learning_node_id' => $node->id,
                'mapping_role' => $data['mapping_role'], 'weight' => $data['weight'] ?? null, 'origin' => 'manual',
                'created_by' => $actorId, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $templateId)
                ->update(['working_revision' => (int) $template->working_revision + 1, 'updated_at' => $now]);
        });
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
            throw ValidationException::withMessages(['source_id' => 'The selected Lesson or Activity does not belong to this Course Template.']);
        }
    }
}
