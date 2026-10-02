<?php

namespace App\Services;

use App\Exceptions\CourseAuthoringContextException;
use App\Exceptions\LearningAuthoringBasisException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Course owner port rebaseFrameworkSelection (contract § P1-1).
 *
 * The ONLY exception to the ordinary selection lock, and only when an admin
 * supplies a complete reviewed plan: every existing Intent of the Template
 * receives exactly one disposition (map or remove_explicit). Because the
 * selection FK is immediate, one transaction removes the old mutable Intents,
 * moves the selection and inserts the mapped replacements; any failure rolls
 * the original set back. No FK is disabled, no published Course Version,
 * Mapping, Product or Enrollment is touched.
 *
 * An AI-origin Intent may only map to the same Definition in the target
 * Version and must carry the new AI rebase_target decision; a manual Intent may
 * select any active Node of the target Version.
 */
final class CourseAuthoringRebaseService
{
    public function __construct(
        private readonly CourseAuthoringContextService $context,
        private readonly LearningAuthoringBasisService $basis,
        private readonly LearningAuthoringTargetService $learning,
    ) {}

    /**
     * @return array{template_id:int,working_revision:int,framework_id:int,from_version_id:int,to_version_id:int,intents:array<int,array<string,mixed>>}
     */
    public function preview(int $adminId, int $activityId, int $frameworkId, int $targetVersionId): array
    {
        $customerId = TenantContext::customerId() ?? throw new CourseAuthoringContextException('not_found');
        $context = $this->context->proposalContext($adminId, $activityId);
        if ($context['actor_role'] !== 'admin') {
            throw new CourseAuthoringContextException('forbidden');
        }
        if ($context['selected_framework_id'] !== $frameworkId || $context['selected_framework_version_id'] === $targetVersionId) {
            throw new CourseAuthoringContextException('framework_selection_conflict');
        }
        try {
            $target = $this->basis->proposalBasis($frameworkId, $targetVersionId);
        } catch (LearningAuthoringBasisException) {
            throw new CourseAuthoringContextException('framework_selection_conflict');
        }
        $byDefinition = [];
        foreach ($target['candidates'] as $candidate) {
            $byDefinition[$candidate['definition_id']][] = $candidate['node_id'];
        }

        $rows = [];
        foreach (DB::table('core_course_template_learning_mapping_intents')->where('customer_id', $customerId)
            ->where('template_id', $context['template_id'])->orderBy('id')->get() as $intent) {
            try {
                $old = $this->learning->targetSnapshot((int) $intent->framework_id, (int) $intent->framework_version_id, (int) $intent->learning_node_id);
            } catch (LearningAuthoringBasisException) {
                throw new CourseAuthoringContextException('target_unavailable');
            }
            $matches = $byDefinition[$old['definition_id']] ?? [];
            $proposed = count($matches) === 1 ? $matches[0] : null;
            $rows[] = [
                'intent_id' => (int) $intent->id, 'origin' => $intent->origin,
                'source_type' => $intent->source_type, 'source_id' => (int) $intent->source_id,
                'mapping_role' => $intent->mapping_role, 'weight' => $intent->weight === null ? null : (string) $intent->weight,
                'old_node_id' => (int) $intent->learning_node_id, 'definition_id' => $old['definition_id'], 'old_target_hash' => $old['target_hash'],
                'proposed_node_id' => $proposed,
                'new_target_hash' => $proposed === null ? null : $this->learning->targetSnapshot($frameworkId, $targetVersionId, $proposed)['target_hash'],
                'ai_proposal_id' => $intent->ai_proposal_id === null ? null : (int) $intent->ai_proposal_id,
                'ai_proposal_revision_id' => $intent->ai_proposal_revision_id === null ? null : (int) $intent->ai_proposal_revision_id,
            ];
        }

        return [
            'template_id' => $context['template_id'],
            'working_revision' => (int) DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $context['template_id'])->value('working_revision'),
            'framework_id' => $frameworkId,
            'from_version_id' => (int) $context['selected_framework_version_id'],
            'to_version_id' => $targetVersionId,
            'intents' => $rows,
        ];
    }

    /**
     * The Intents of ONE Template that point at the given Nodes, with a readable name for what they are attached to.
     * Other Templates that share the Framework are not looked at. At most 100.
     *
     * @param  array<int,int>  $nodeIds
     * @return array<int,array{intent_id:int,node_id:int,source_label:string}>
     */
    public function intentsOnNodes(int $templateId, array $nodeIds): array
    {
        $customerId = TenantContext::customerId() ?? throw new CourseAuthoringContextException('not_found');
        if ($nodeIds === []) {
            return [];
        }
        $rows = [];
        foreach (DB::table('core_course_template_learning_mapping_intents')->where('customer_id', $customerId)
            ->where('template_id', $templateId)->whereIn('learning_node_id', $nodeIds)->orderBy('id')->limit(100)
            ->get(['id', 'learning_node_id', 'source_type', 'source_id']) as $intent) {
            $rows[] = ['intent_id' => (int) $intent->id, 'node_id' => (int) $intent->learning_node_id, 'source_label' => $this->sourceLabel($intent->source_type, (int) $intent->source_id)];
        }

        return $rows;
    }

    /** The title of the Lesson or Activity an Intent is attached to (at most 255 characters; empty if it is gone). */
    public function sourceLabel(string $sourceType, int $sourceId): string
    {
        $customerId = TenantContext::customerId() ?? throw new CourseAuthoringContextException('not_found');
        $table = match ($sourceType) {
            'course_template_lesson' => 'core_course_template_lessons',
            'course_template_activity' => 'core_course_template_activities',
            default => null,
        };
        $title = $table === null ? null : DB::table($table)->where('customer_id', $customerId)->where('id', $sourceId)->value('title');

        return mb_substr((string) ($title ?? ''), 0, 255);
    }

    /**
     * Runs inside the caller's transaction (Template lock taken here first).
     *
     * @param  array<int,array{disposition:string,node_id?:int,reason?:string,ai_review_id?:int}>  $dispositions  keyed by Intent id
     * @return array<int,int|null> old Intent id => replacement Intent id (null when removed)
     */
    public function rebase(int $adminId, int $activityId, int $frameworkId, int $targetVersionId, int $expectedWorkingRevision, array $dispositions): array
    {
        $customerId = TenantContext::customerId() ?? throw new CourseAuthoringContextException('not_found');
        $context = $this->context->proposalContext($adminId, $activityId, true);
        if ($context['actor_role'] !== 'admin') {
            throw new CourseAuthoringContextException('forbidden');
        }
        $template = DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $context['template_id'])->first();
        if ((int) $template->working_revision !== $expectedWorkingRevision || (int) $template->selected_learning_framework_id !== $frameworkId) {
            throw new CourseAuthoringContextException('confirmation_conflict');
        }
        try {
            $this->basis->proposalBasis($frameworkId, $targetVersionId);
        } catch (LearningAuthoringBasisException) {
            throw new CourseAuthoringContextException('framework_selection_conflict');
        }

        $intents = DB::table('core_course_template_learning_mapping_intents')->where('customer_id', $customerId)
            ->where('template_id', $template->id)->orderBy('id')->lockForUpdate()->get()->keyBy(fn (object $i): int => (int) $i->id);
        $planned = array_map('intval', array_keys($dispositions));
        sort($planned);
        if ($planned !== $intents->keys()->all()) {
            // Every Intent needs exactly one disposition; none may silently disappear.
            throw new CourseAuthoringContextException('rebase_plan_incomplete');
        }

        $replacements = [];
        foreach ($intents as $id => $intent) {
            $plan = $dispositions[$id];
            if ($plan['disposition'] === 'remove_explicit') {
                if (! is_string($plan['reason'] ?? null) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $plan['reason']) !== 1) {
                    throw new CourseAuthoringContextException('rebase_plan_incomplete');
                }

                continue;
            }
            if ($plan['disposition'] !== 'map' || ! is_int($plan['node_id'] ?? null)) {
                throw new CourseAuthoringContextException('rebase_plan_incomplete');
            }
            try {
                $old = $this->learning->targetSnapshot((int) $intent->framework_id, (int) $intent->framework_version_id, (int) $intent->learning_node_id);
                $new = $this->learning->targetSnapshot($frameworkId, $targetVersionId, $plan['node_id']);
            } catch (LearningAuthoringBasisException) {
                throw new CourseAuthoringContextException('target_unavailable');
            }
            if ($new['node_status'] !== 'active' || $new['version_status'] !== 'published') {
                throw new CourseAuthoringContextException('target_unavailable');
            }
            if ($intent->origin === 'ai_proposal' && ($new['definition_id'] !== $old['definition_id'] || ! is_int($plan['ai_review_id'] ?? null))) {
                // A different meaning for an AI decision needs a human successor.
                throw new CourseAuthoringContextException('rebase_requires_successor');
            }
            $replacements[$id] = $plan;
        }

        DB::table('core_course_template_learning_mapping_intents')->where('customer_id', $customerId)
            ->whereIn('id', $intents->keys()->all())->delete();
        $now = now();
        DB::table('core_course_templates')->where('id', $template->id)->update([
            'selected_learning_framework_version_id' => $targetVersionId,
            'working_revision' => (int) $template->working_revision + 1, 'updated_at' => $now,
        ]);

        $result = array_fill_keys($intents->keys()->all(), null);
        foreach ($replacements as $id => $plan) {
            $intent = $intents[$id];
            $ai = $intent->origin === 'ai_proposal';
            $result[$id] = (int) DB::table('core_course_template_learning_mapping_intents')->insertGetId([
                'customer_id' => $customerId, 'template_id' => $template->id,
                'source_type' => $intent->source_type, 'source_id' => $intent->source_id,
                'framework_id' => $frameworkId, 'framework_version_id' => $targetVersionId,
                'learning_node_id' => $plan['node_id'], 'mapping_role' => $intent->mapping_role, 'weight' => $intent->weight,
                'origin' => $intent->origin,
                'ai_proposal_id' => $ai ? $intent->ai_proposal_id : null,
                'ai_proposal_revision_id' => $ai ? $intent->ai_proposal_revision_id : null,
                // The rebase decision confirms both the new target and the current context.
                'ai_target_review_id' => $ai ? $plan['ai_review_id'] : null,
                'ai_context_review_id' => $ai ? $plan['ai_review_id'] : null,
                'created_by' => $adminId, 'updated_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $result;
    }
}
