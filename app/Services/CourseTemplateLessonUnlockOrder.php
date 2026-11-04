<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The order of working Lessons and the unlock rules that depend on it. Every change of order (Lesson group, Lesson form,
 * Section form, Lesson delete) checks here; the publish check reads the same rules.
 */
class CourseTemplateLessonUnlockOrder
{
    /**
     * The Lessons of a lane in their canonical order: direct Lessons when $sectionId is null, otherwise every Sectioned
     * Lesson. With $excludedLessonId, only those before that Lesson (at $prospectiveSortOrder, when given).
     */
    public function orderedLessons(
        int $customerId,
        int $templateId,
        ?int $sectionId,
        ?int $excludedLessonId = null,
        ?int $prospectiveSortOrder = null
    ) {
        $lessons = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $sectionId === null,
                fn ($query) => $query->whereNull('template_section_id'),
                fn ($query) => $query->whereNotNull('template_section_id')
            )
            ->orderBy('template_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $targetLessonId = $excludedLessonId;
        if ($prospectiveSortOrder !== null) {
            if ($targetLessonId === null) {
                $targetLessonId = PHP_INT_MAX;
                $lessons->push((object) [
                    'id' => $targetLessonId,
                    'template_section_id' => $sectionId,
                    'title' => '',
                    'sort_order' => $prospectiveSortOrder,
                ]);
            } else {
                $target = $lessons->firstWhere('id', $targetLessonId);
                if ($target) {
                    $target->sort_order = $prospectiveSortOrder;
                }
            }
        }

        if ($sectionId === null) {
            $ordered = $lessons->sortBy(fn (object $lesson): string => sprintf(
                '%010d:%020d',
                $lesson->sort_order,
                $lesson->id
            ))->values();

            return $targetLessonId === null
                ? $ordered
                : $ordered->takeUntil(
                    fn (object $lesson): bool => (int) $lesson->id === $targetLessonId
                )->values();
        }

        $sections = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->get()->keyBy('id');

        $sectionOrderKeys = [];
        $children = $sections->groupBy(
            fn (object $section): int => (int) ($section->parent_section_id ?? 0)
        );
        $walk = function (int $parentId, array $parentKey = []) use (
            &$walk,
            &$sectionOrderKeys,
            $children
        ): void {
            $siblings = $children->get($parentId, collect())->sortBy(
                fn (object $section): string => sprintf(
                    '%010d:%020d',
                    $section->display_order,
                    $section->id
                )
            );
            foreach ($siblings as $section) {
                $key = [...$parentKey, sprintf(
                    'S%010d:%020d',
                    $section->display_order,
                    $section->id
                )];
                $sectionOrderKeys[$section->id] = implode('/', $key);
                $walk((int) $section->id, $key);
            }
        };
        $walk(0);

        foreach ($lessons as $lesson) {
            $labels = [];
            $currentId = (int) $lesson->template_section_id;
            $visited = [];
            while ($currentId && isset($sections[$currentId]) && ! isset($visited[$currentId])) {
                $visited[$currentId] = true;
                array_unshift($labels, $sections[$currentId]->title);
                $currentId = (int) ($sections[$currentId]->parent_section_id ?? 0);
            }
            $lesson->option_label = implode(' › ', [...$labels, $lesson->title]);
        }

        $ordered = $lessons->sortBy(function (object $lesson) use ($sectionOrderKeys): string {
            return ($sectionOrderKeys[$lesson->template_section_id] ?? '')
                .sprintf('/L%010d:%020d', $lesson->sort_order, $lesson->id);
        })->values();

        return $targetLessonId === null
            ? $ordered
            : $ordered->takeUntil(
                fn (object $lesson): bool => (int) $lesson->id === $targetLessonId
            )->values();
    }

    /**
     * The unlock rules broken by the current order, as the publish check reads them
     * (CourseTemplatePublishGraphValidator): a prerequisite must come first in the same lane, and "all previous lessons"
     * needs a lesson before it. Keyed by lesson and prerequisite, with the message for the author.
     *
     * @return array<string, string>
     */
    public function violations(
        int $customerId,
        int $templateId,
        bool $sectioned
    ): array {
        $lessons = $this->orderedLessons($customerId, $templateId, $sectioned ? 0 : null)->values();
        $byId = $lessons->keyBy(fn (object $lesson): int => (int) $lesson->id);
        $positions = $byId->keys()->flip();
        $selected = DB::table('core_course_template_lesson_prerequisites')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->get()
            ->groupBy(fn (object $edge): int => (int) $edge->lesson_id);
        $violations = [];

        foreach ($lessons as $position => $lesson) {
            if ($lesson->unlock_rule === 'all_previous_lessons_completed' && $position === 0) {
                $violations[$lesson->id.':first'] = __('lf.LF_course_template_lesson_reorder_blocked_first', [
                    'lesson' => $lesson->title,
                ]);
            }

            $prerequisiteIds = match ($lesson->unlock_rule) {
                'selected_lessons_completed' => $selected->get((int) $lesson->id, collect())
                    ->pluck('prerequisite_lesson_id')->all(),
                'previous_lesson_completed' => array_filter([$lesson->unlock_after_lesson_id]),
                default => [],
            };

            foreach ($prerequisiteIds as $prerequisiteId) {
                $prerequisiteId = (int) $prerequisiteId;

                if ($positions->has($prerequisiteId) && $positions[$prerequisiteId] >= $position) {
                    $violations[$lesson->id.':'.$prerequisiteId] = __('lf.LF_course_template_lesson_reorder_blocked_prerequisite', [
                        'lesson' => $lesson->title,
                        'prerequisite' => $byId[$prerequisiteId]->title,
                    ]);
                }
            }
        }

        return $violations;
    }

    /** Why this Lesson cannot leave its lane: the next one would be first while it needs a Lesson before it. */
    public function deleteViolation(
        int $customerId,
        int $templateId,
        int $lessonId,
        bool $sectioned
    ): ?string {
        $lessons = $this->orderedLessons($customerId, $templateId, $sectioned ? 0 : null)->values();
        $next = $lessons->get(1);

        if ((int) ($lessons->first()?->id ?? 0) !== $lessonId
            || $next?->unlock_rule !== 'all_previous_lessons_completed') {
            return null;
        }

        return __('lf.LF_course_template_lesson_delete_blocked_first', ['lesson' => $next->title]);
    }
}
