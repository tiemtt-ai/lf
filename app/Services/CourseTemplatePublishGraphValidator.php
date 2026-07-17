<?php

namespace App\Services;

use App\Support\CourseTemplateStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CourseTemplatePublishGraphValidator
{
    private const LESSON_TYPES = ['regular', 'review', 'midterm_exam', 'final_exam', 'other_exam'];
    private const LESSON_UNLOCK_RULES = ['none', 'previous_lesson_completed', 'date_based'];
    private const ACTIVITY_TYPES = ['video', 'embedded_video', 'audio', 'document', 'quiz', 'live_class'];
    private const ACTIVITY_UNLOCK_RULES = ['none', 'previous_activity_completed', 'previous_lesson_completed', 'date_based'];

    public function evaluate(int $customerId, object $template, Collection $sections, Collection $lessons, Collection $activities): CourseTemplatePublishReadiness
    {
        $issues = collect();
        if ((int) $template->customer_id !== $customerId) {
            $this->add($issues, 'template', 'information');

            return new CourseTemplatePublishReadiness($issues);
        }
        $templateStatus = (string) $template->status;
        if (! CourseTemplateStatus::canPublish($templateStatus)) {
            $this->add($issues, 'template_status', 'information', [
                'status' => __('lf.LF_course_template_common_'.$templateStatus),
            ]);
        }
        if (blank($template->title) || blank($template->publisher_name)) {
            $this->add($issues, 'template', 'information');
        }
        if ($lessons->isEmpty()) {
            $this->add($issues, 'empty_content', 'content');
        }

        $sectionMap = $sections->keyBy('id');
        $lessonMap = $lessons->keyBy('id');
        $activityMap = $activities->keyBy('id');
        $this->validateSections($customerId, (int) $template->id, $sections, $sectionMap, $issues);
        $this->validateLessons($customerId, (int) $template->id, $lessons, $lessonMap, $sectionMap, $activities, $issues);
        $this->validateActivities($customerId, (int) $template->id, $activities, $activityMap, $lessonMap, $issues);
        $this->validateTemplateMedia($customerId, (int) $template->id, $template, $issues);
        $this->validateVideoState($template, $issues);

        return new CourseTemplatePublishReadiness($issues);
    }

    public function validate(int $customerId, object $template, Collection $sections, Collection $lessons, Collection $activities): void
    {
        $this->evaluate($customerId, $template, $sections, $lessons, $activities)->assertReady();
    }

    private function validateSections(int $customerId, int $templateId, Collection $sections, Collection $map, Collection $issues): void
    {
        $parents = [];
        foreach ($sections as $section) {
            if ((int) $section->customer_id !== $customerId || (int) $section->template_id !== $templateId || ! $this->nonNegativeInteger($section->display_order)) {
                $this->add($issues, 'section', 'content');
                continue;
            }
            $parentId = $section->parent_section_id ? (int) $section->parent_section_id : null;
            if ($parentId && ($parentId === (int) $section->id || ! $map->has($parentId))) {
                $this->add($issues, 'section', 'content');
            }
            $parents[(int) $section->id] = $parentId;
        }
        if ($this->hasCycle($parents)) {
            $this->add($issues, 'section', 'content');
        }
    }

    private function validateLessons(int $customerId, int $templateId, Collection $lessons, Collection $map, Collection $sections, Collection $activities, Collection $issues): void
    {
        $parents = [];
        foreach ($lessons as $lesson) {
            $context = ['lesson' => $lesson->title];
            $section = $lesson->template_section_id ? $sections->get($lesson->template_section_id) : null;
            if ((int) $lesson->customer_id !== $customerId || (int) $lesson->template_id !== $templateId || ! in_array($lesson->lesson_type, self::LESSON_TYPES, true) || ! $this->nonNegativeInteger($lesson->sort_order) || ! $this->nonNegativeInteger($lesson->duration_seconds) || ($lesson->template_section_id && (! $section || ! $section->allows_lessons))) {
                $this->add($issues, 'lesson', 'content', $context, $this->lessonFragment($lesson));
            }
            $expectedDuration = (int) $activities->where('template_lesson_id', $lesson->id)->sum('estimated_duration_seconds');
            if ((int) $lesson->duration_seconds !== $expectedDuration) {
                $this->add($issues, 'lesson_duration', 'content', $context, $this->lessonFragment($lesson));
            }
            $rule = $lesson->unlock_rule;
            $prerequisite = $lesson->unlock_after_lesson_id ? (int) $lesson->unlock_after_lesson_id : null;
            if (! in_array($rule, self::LESSON_UNLOCK_RULES, true)
                || ($rule === 'none' && ($prerequisite || $lesson->unlock_at))
                || ($rule === 'previous_lesson_completed' && (! $prerequisite || $lesson->unlock_at || ! $map->has($prerequisite)))
                || ($rule === 'date_based' && ($prerequisite || ! $lesson->unlock_at))) {
                $this->add($issues, 'lesson_unlock', 'content', $context, $this->lessonFragment($lesson));
            }
            $parents[(int) $lesson->id] = $rule === 'previous_lesson_completed' ? $prerequisite : null;
        }
        if ($this->hasCycle($parents)) {
            $this->add($issues, 'lesson_unlock', 'content');
        }
    }

    private function validateActivities(int $customerId, int $templateId, Collection $activities, Collection $map, Collection $lessons, Collection $issues): void
    {
        $mediaByActivity = DB::table('media_file_usages as usages')
            ->join('media_files as media', 'media.id', '=', 'usages.media_file_id')
            ->where('usages.customer_id', $customerId)
            ->where('usages.owner_type', 'course_activity')
            ->whereIn('usages.owner_id', $activities->pluck('id'))
            ->select('usages.owner_id', 'usages.usage_type', 'usages.status as usage_status', 'media.customer_id as media_customer_id', 'media.file_type', 'media.mime_type', 'media.status as media_status')
            ->get()->groupBy('owner_id');
        $parents = [];
        foreach ($activities as $activity) {
            $lesson = $lessons->get($activity->template_lesson_id);
            $context = [
                'lesson' => $lesson?->title ?? __('lf.LF_course_template_publish_integrity_unknown_lesson'),
                'activity' => $activity->title,
                'type' => $activity->activity_type,
            ];
            $fragment = $lesson ? $this->lessonFragment($lesson) : null;
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
            if ((int) $activity->customer_id !== $customerId || (int) $activity->template_id !== $templateId || ! $lesson || ! in_array($type, self::ACTIVITY_TYPES, true) || ! $this->nonNegativeInteger($activity->sort_order) || ($estimated !== null && (! $this->positiveInteger($estimated) || (int) $estimated > 31536000)) || ! in_array($activity->completion_rule, $completionRules, true) || ! in_array((int) $activity->is_required, [0, 1], true) || ! in_array((int) $activity->is_preview, [0, 1], true)) {
                $this->add($issues, 'activity', 'content', $context, $fragment);
            }
            if (($thresholdRequired && (! $this->nonNegativeInteger($activity->completion_threshold) || (int) $activity->completion_threshold > 100)) || (! $thresholdRequired && $activity->completion_threshold !== null)) {
                $this->add($issues, 'activity_completion', 'content', $context, $fragment);
            }
            if (($type === 'embedded_video' && blank($activity->external_video_url)) || ($type !== 'embedded_video' && $activity->external_video_url !== null) || ($type === 'live_class' && blank($activity->live_class_url)) || ($type !== 'live_class' && $activity->live_class_url !== null) || ($type === 'quiz' && ! $activity->assessment_quiz_id) || ($type !== 'quiz' && $activity->assessment_quiz_id !== null)) {
                $this->add($issues, 'activity_source', 'content', $context, $fragment);
            }

            $activityMedia = $mediaByActivity->get($activity->id, collect());
            $invalidMedia = false;
            foreach ($activityMedia as $media) {
                $expectedType = in_array($type, ['video', 'audio', 'document'], true) ? $type : 'attachment';
                if ((int) $media->media_customer_id !== $customerId || $media->media_status !== 'ready' || $media->usage_status !== 'active' || ($media->usage_type !== 'attachment' && ($media->usage_type !== $expectedType || ! $this->mediaTypeMatches($expectedType, $media)))) {
                    $invalidMedia = true;
                }
            }
            if (in_array($type, ['video', 'audio', 'document'], true)) {
                $invalidMedia = $invalidMedia || $activityMedia->where('usage_type', $type)->count() !== 1;
            } elseif ($activityMedia->whereIn('usage_type', ['video', 'audio', 'document'])->isNotEmpty()) {
                $invalidMedia = true;
            }
            if ($invalidMedia) {
                $this->add($issues, 'activity_media', 'content', $context, $fragment);
            }

            $rule = $activity->unlock_rule;
            $prerequisite = $activity->unlock_after_activity_id ? (int) $activity->unlock_after_activity_id : null;
            $target = $prerequisite ? $map->get($prerequisite) : null;
            if (! in_array($rule, self::ACTIVITY_UNLOCK_RULES, true) || ($rule === 'none' && ($prerequisite || $activity->unlock_at)) || ($rule === 'previous_activity_completed' && (! $target || $activity->unlock_at || (int) $target->template_lesson_id !== (int) $activity->template_lesson_id)) || ($rule === 'date_based' && ($prerequisite || ! $activity->unlock_at))) {
                $this->add($issues, 'activity_unlock', 'content', $context, $fragment);
            }
            $parents[(int) $activity->id] = $rule === 'previous_activity_completed' ? $prerequisite : null;
        }
        if ($this->hasCycle($parents)) {
            $this->add($issues, 'activity_unlock', 'content');
        }
    }

    private function validateTemplateMedia(int $customerId, int $templateId, object $template, Collection $issues): void
    {
        foreach ([['intro_image_media_file_id', 'intro_image', 'image'], ['intro_video_media_file_id', 'intro_video', 'video'], ['intro_document_media_file_id', 'intro_document', 'document']] as [$field, $usageType, $fileType]) {
            $mediaId = $template->{$field};
            if (! $mediaId) {
                continue;
            }
            $valid = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaId)->where('status', 'ready')->where('file_type', $fileType)->exists()
                && DB::table('media_file_usages')->where('customer_id', $customerId)->where('media_file_id', $mediaId)->where('owner_type', 'course_template')->where('owner_id', $templateId)->where('usage_type', $usageType)->where('status', 'active')->exists();
            if (! $valid) {
                $this->add($issues, 'template_'.$usageType, 'information', ['slot' => $usageType]);
            }
        }
    }

    private function validateVideoState(object $template, Collection $issues): void
    {
        $valid = match ($template->intro_video_source) {
            null => ! $template->intro_video_media_file_id && ! $template->intro_video_embed_url && ! $template->intro_video_provider,
            'upload' => (bool) $template->intro_video_media_file_id && ! $template->intro_video_embed_url && ! $template->intro_video_provider,
            'embed' => ! $template->intro_video_media_file_id && (bool) $template->intro_video_embed_url && in_array($template->intro_video_provider, ['youtube', 'vimeo'], true),
            default => false,
        };
        if (! $valid) {
            $this->add($issues, 'video_state', 'information', [], null, 'lf.LF_course_template_invalid_video_state');
        }
    }

    private function add(Collection $issues, string $code, string $targetTab, array $context = [], ?string $fragment = null, ?string $messageKey = null): void
    {
        $dedupeKey = $code.'|'.($context['lesson'] ?? '').'|'.($context['activity'] ?? '').'|'.($context['slot'] ?? '');
        if ($issues->contains(fn (CourseTemplatePublishIssue $issue) => $issue->code.'|'.($issue->context['lesson'] ?? '').'|'.($issue->context['activity'] ?? '').'|'.($issue->context['slot'] ?? '') === $dedupeKey)) {
            return;
        }
        $contextualKey = match (true) {
            $code === 'lesson' && isset($context['lesson']) => 'lf.LF_course_template_publish_integrity_lesson_context',
            $code === 'lesson_duration' && isset($context['lesson']) => 'lf.LF_course_template_publish_integrity_lesson_duration_context',
            $code === 'lesson_unlock' && isset($context['lesson']) => 'lf.LF_course_template_publish_integrity_lesson_unlock_context',
            $code === 'activity' && isset($context['activity']) => 'lf.LF_course_template_publish_integrity_activity_context',
            $code === 'activity_source' && isset($context['activity']) => 'lf.LF_course_template_publish_integrity_activity_source_context',
            $code === 'activity_completion' && isset($context['activity']) => 'lf.LF_course_template_publish_integrity_activity_completion_context',
            $code === 'activity_unlock' && isset($context['activity']) => 'lf.LF_course_template_publish_integrity_activity_unlock_context',
            default => null,
        };
        $issues->push(new CourseTemplatePublishIssue(
            $code,
            $messageKey ?? $contextualKey ?? 'lf.LF_course_template_publish_integrity_'.$code,
            $targetTab,
            $context,
            $fragment,
        ));
    }

    private function lessonFragment(object $lesson): string
    {
        return 'course-template-lesson-'.$lesson->id.'-activities';
    }

    private function hasCycle(array $parents): bool
    {
        foreach (array_keys($parents) as $id) {
            $seen = [];
            for ($current = $id; $current && isset($parents[$current]); $current = $parents[$current]) {
                if (isset($seen[$current])) {
                    return true;
                }
                $seen[$current] = true;
            }
        }

        return false;
    }

    private function nonNegativeInteger(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value >= 0;
    }

    private function positiveInteger(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0;
    }

    private function mediaTypeMatches(string $expectedType, object $media): bool
    {
        $mimes = [
            'video' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo'],
            'audio' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/webm', 'audio/mp4'],
            'document' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'text/plain'],
        ];

        return $media->file_type === $expectedType && in_array($media->mime_type, $mimes[$expectedType] ?? [], true);
    }
}
