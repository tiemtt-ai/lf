<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseTemplatePublishGraphValidator
{
    private const LESSON_TYPES = ['regular', 'review', 'midterm_exam', 'final_exam', 'other_exam'];
    private const LESSON_UNLOCK_RULES = ['none', 'previous_lesson_completed', 'date_based'];
    private const ACTIVITY_TYPES = ['video', 'embedded_video', 'audio', 'document', 'quiz', 'live_class'];
    private const ACTIVITY_UNLOCK_RULES = ['none', 'previous_activity_completed', 'previous_lesson_completed', 'date_based'];

    public function validate(int $customerId, object $template, Collection $sections, Collection $lessons, Collection $activities): void
    {
        if ((int) $template->customer_id !== $customerId || blank($template->title) || blank($template->publisher_name)) {
            $this->fail('template');
        }

        $sectionMap = $sections->keyBy('id');
        $lessonMap = $lessons->keyBy('id');
        $activityMap = $activities->keyBy('id');
        $this->validateSections($customerId, (int) $template->id, $sections, $sectionMap);
        $this->validateLessons($customerId, (int) $template->id, $lessons, $lessonMap, $sectionMap, $activities);
        $this->validateActivities($customerId, (int) $template->id, $activities, $activityMap, $lessonMap);
        $this->validateTemplateMedia($customerId, (int) $template->id, $template);
    }

    private function validateSections(int $customerId, int $templateId, Collection $sections, Collection $map): void
    {
        $parents = [];
        foreach ($sections as $section) {
            if ((int) $section->customer_id !== $customerId || (int) $section->template_id !== $templateId || ! $this->nonNegativeInteger($section->display_order)) $this->fail('section');
            $parentId = $section->parent_section_id ? (int) $section->parent_section_id : null;
            if ($parentId && ($parentId === (int) $section->id || ! $map->has($parentId))) $this->fail('section');
            $parents[(int) $section->id] = $parentId;
        }
        $this->assertAcyclic($parents, 'section');
    }

    private function validateLessons(int $customerId, int $templateId, Collection $lessons, Collection $map, Collection $sections, Collection $activities): void
    {
        $parents = [];
        foreach ($lessons as $lesson) {
            $section = $lesson->template_section_id ? $sections->get($lesson->template_section_id) : null;
            if ((int) $lesson->customer_id !== $customerId || (int) $lesson->template_id !== $templateId || ! in_array($lesson->lesson_type, self::LESSON_TYPES, true) || ! $this->nonNegativeInteger($lesson->sort_order) || ! $this->nonNegativeInteger($lesson->duration_seconds) || ($lesson->template_section_id && (! $section || ! $section->allows_lessons))) $this->fail('lesson');
            $expectedDuration = (int) $activities->where('template_lesson_id', $lesson->id)->sum('estimated_duration_seconds');
            if ((int) $lesson->duration_seconds !== $expectedDuration) $this->fail('lesson_duration');
            $rule = $lesson->unlock_rule;
            $prerequisite = $lesson->unlock_after_lesson_id ? (int) $lesson->unlock_after_lesson_id : null;
            if (! in_array($rule, self::LESSON_UNLOCK_RULES, true)
                || ($rule === 'none' && ($prerequisite || $lesson->unlock_at))
                || ($rule === 'previous_lesson_completed' && (! $prerequisite || $lesson->unlock_at || ! $map->has($prerequisite)))
                || ($rule === 'date_based' && ($prerequisite || ! $lesson->unlock_at))) $this->fail('lesson_unlock');
            $parents[(int) $lesson->id] = $rule === 'previous_lesson_completed' ? $prerequisite : null;
        }
        $this->assertAcyclic($parents, 'lesson_unlock');
    }

    private function validateActivities(int $customerId, int $templateId, Collection $activities, Collection $map, Collection $lessons): void
    {
        $mediaByActivity = DB::table('media_file_usages as usages')
            ->join('media_files as media', 'media.id', '=', 'usages.media_file_id')
            ->where('usages.customer_id', $customerId)
            ->where('usages.owner_type', 'course_activity')
            ->whereIn('usages.owner_id', $activities->pluck('id'))
            ->select('usages.owner_id', 'usages.usage_type', 'usages.status as usage_status', 'media.customer_id as media_customer_id', 'media.file_type', 'media.status as media_status')
            ->get()->groupBy('owner_id');
        $parents = [];
        foreach ($activities as $activity) {
            $type = $activity->activity_type;
            $completionRules = match ($type) {
                'video', 'audio' => ['view', 'watch_percent', 'manual'],
                'document', 'embedded_video' => ['view', 'manual'],
                'quiz' => ['submit', 'pass', 'manual'],
                'live_class' => ['join', 'manual'],
                default => [],
            };
            $thresholdRequired = in_array($activity->completion_rule, ['watch_percent', 'pass'], true);
            $estimated = $activity->estimated_duration_seconds;
            if ((int) $activity->customer_id !== $customerId || (int) $activity->template_id !== $templateId || ! $lessons->has($activity->template_lesson_id) || ! in_array($type, self::ACTIVITY_TYPES, true) || ! $this->nonNegativeInteger($activity->sort_order) || ($estimated !== null && (! $this->positiveInteger($estimated) || (int) $estimated > 31536000)) || ! in_array($activity->completion_rule, $completionRules, true) || ! in_array((int) $activity->is_required, [0, 1], true) || ! in_array((int) $activity->is_preview, [0, 1], true)) $this->fail('activity');
            if (($thresholdRequired && (! $this->nonNegativeInteger($activity->completion_threshold) || (int) $activity->completion_threshold > 100)) || (! $thresholdRequired && $activity->completion_threshold !== null)) $this->fail('activity_completion');
            if (($type === 'embedded_video' && blank($activity->external_video_url)) || ($type !== 'embedded_video' && $activity->external_video_url !== null) || ($type === 'live_class' && blank($activity->live_class_url)) || ($type !== 'live_class' && $activity->live_class_url !== null) || ($type === 'quiz' && ! $activity->assessment_quiz_id) || ($type !== 'quiz' && $activity->assessment_quiz_id !== null)) $this->fail('activity_source');
            foreach ($mediaByActivity->get($activity->id, collect()) as $media) {
                $expectedType = in_array($type, ['video', 'audio', 'document'], true) ? $type : 'attachment';
                if ((int) $media->media_customer_id !== $customerId || $media->media_status !== 'ready' || $media->usage_status !== 'active' || ($media->usage_type !== 'attachment' && ($media->usage_type !== $expectedType || $media->file_type !== $expectedType))) $this->fail('media');
            }
            if (in_array($type, ['video', 'audio', 'document'], true)) {
                $matching = $mediaByActivity->get($activity->id, collect())
                    ->where('usage_type', $type);
                if ($matching->count() !== 1) $this->fail('media');
            } elseif ($mediaByActivity->get($activity->id, collect())->whereIn('usage_type', ['video', 'audio', 'document'])->isNotEmpty()) {
                $this->fail('media');
            }
            $rule = $activity->unlock_rule;
            $prerequisite = $activity->unlock_after_activity_id ? (int) $activity->unlock_after_activity_id : null;
            $target = $prerequisite ? $map->get($prerequisite) : null;
            if (! in_array($rule, self::ACTIVITY_UNLOCK_RULES, true) || ($rule === 'none' && ($prerequisite || $activity->unlock_at)) || ($rule === 'previous_activity_completed' && (! $target || $activity->unlock_at || (int) $target->template_lesson_id !== (int) $activity->template_lesson_id)) || ($rule === 'date_based' && ($prerequisite || ! $activity->unlock_at))) $this->fail('activity_unlock');
            $parents[(int) $activity->id] = $rule === 'previous_activity_completed' ? $prerequisite : null;
        }
        $this->assertAcyclic($parents, 'activity_unlock');
    }

    private function validateTemplateMedia(int $customerId, int $templateId, object $template): void
    {
        foreach ([['intro_image_media_file_id', 'intro_image', 'image'], ['intro_video_media_file_id', 'intro_video', 'video'], ['intro_document_media_file_id', 'intro_document', 'document']] as [$field, $usageType, $fileType]) {
            $mediaId = $template->{$field};
            if (! $mediaId) continue;
            $valid = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaId)->where('status', 'ready')->where('file_type', $fileType)->exists()
                && DB::table('media_file_usages')->where('customer_id', $customerId)->where('media_file_id', $mediaId)->where('owner_type', 'course_template')->where('owner_id', $templateId)->where('usage_type', $usageType)->where('status', 'active')->exists();
            if (! $valid) $this->fail('media');
        }
    }

    private function assertAcyclic(array $parents, string $code): void
    {
        foreach (array_keys($parents) as $id) {
            $seen = [];
            for ($current = $id; $current && isset($parents[$current]); $current = $parents[$current]) {
                if (isset($seen[$current])) $this->fail($code);
                $seen[$current] = true;
            }
        }
    }

    private function nonNegativeInteger(mixed $value): bool { return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value >= 0; }
    private function positiveInteger(mixed $value): bool { return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0; }
    private function fail(string $code): never { throw ValidationException::withMessages(['publish' => __('lf.LF_course_template_publish_integrity_'.$code)]); }
}
