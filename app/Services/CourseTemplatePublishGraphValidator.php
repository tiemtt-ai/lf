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

    private const ACTIVITY_UNLOCK_RULES = ['none', 'previous_activity_completed'];

    public function __construct(
        private readonly MediaService $mediaService,
        private readonly TrustedVideoUrlService $trustedVideoUrls,
    ) {}

    public function evaluate(int $customerId, object $template, Collection $sections, Collection $lessons, Collection $activities): CourseTemplatePublishReadiness
    {
        $issues = collect();
        $warnings = collect();
        if ((int) $template->customer_id !== $customerId) {
            $this->add($issues, 'template', 'information');

            return new CourseTemplatePublishReadiness($issues, $warnings);
        }
        $templateStatus = (string) $template->status;
        if (! CourseTemplateStatus::canPublish($templateStatus)) {
            $this->add($issues, 'template_status', 'information', [
                'status' => __('lf.LF_course_template_common_'.$templateStatus),
            ], 'status');
        }
        $this->validateTemplateInformation($customerId, $template, $lessons, $issues, $warnings);
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

        return new CourseTemplatePublishReadiness($issues, $warnings);
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
            $context = ['lesson' => is_string($lesson->title) ? $lesson->title : (string) $lesson->id];
            $section = $lesson->template_section_id ? $sections->get($lesson->template_section_id) : null;
            if ((int) $lesson->customer_id !== $customerId
                || (int) $lesson->template_id !== $templateId
                || ! $this->validRequiredText($lesson->title, 255)
                || ! in_array($lesson->lesson_type, self::LESSON_TYPES, true)
                || ! $this->canonicalBoolean($lesson->is_preview)
                || ! $this->nonNegativeInteger($lesson->sort_order)
                || ! $this->nonNegativeInteger($lesson->duration_seconds)
                || ($lesson->description !== null && ! is_string($lesson->description))
                || ($lesson->short_description !== null && (! is_string($lesson->short_description) || mb_strlen($lesson->short_description) > 500))
                || ($lesson->template_section_id && (! $section || ! $section->allows_lessons))) {
                $this->add($issues, 'lesson', 'content', $context, $this->lessonFragment($lesson));
            }
            $lessonActivities = $activities->where('template_lesson_id', $lesson->id);
            if ($lessonActivities->isEmpty()) {
                $this->add($issues, 'lesson_empty', 'content', $context, $this->lessonFragment($lesson));
            }
            $expectedDuration = (int) $lessonActivities->sum('estimated_duration_seconds');
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
            ->leftJoin('media_files as media', 'media.id', '=', 'usages.media_file_id')
            ->where('usages.owner_type', 'course_activity')
            ->whereIn('usages.owner_id', $activities->pluck('id'))
            ->select('usages.owner_id', 'usages.customer_id as usage_customer_id', 'usages.media_file_id', 'usages.usage_type', 'usages.status as usage_status', 'media.id as resolved_media_id', 'media.customer_id as media_customer_id', 'media.file_type', 'media.mime_type', 'media.extension', 'media.status as media_status')
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
                'live_class' => ['manual'],
                default => [],
            };
            $thresholdRequired = in_array($activity->completion_rule, ['watch_percent', 'pass'], true);
            $estimated = $activity->estimated_duration_seconds;
            if ((int) $activity->customer_id !== $customerId
                || (int) $activity->template_id !== $templateId
                || ! $lesson
                || ! $this->validRequiredText($activity->title, 255)
                || ($activity->description !== null && ! is_string($activity->description))
                || ! in_array($type, self::ACTIVITY_TYPES, true)
                || ! $this->nonNegativeInteger($activity->sort_order)
                || ($estimated !== null && (! $this->positiveInteger($estimated) || (int) $estimated > 31536000))
                || ! in_array($activity->completion_rule, $completionRules, true)
                || ! $this->canonicalBoolean($activity->is_required)
                || ! $this->canonicalBoolean($activity->is_preview)) {
                $this->add($issues, 'activity', 'content', $context, $fragment);
            }
            if (($thresholdRequired && (! $this->positiveInteger($activity->completion_threshold) || (int) $activity->completion_threshold > 100)) || (! $thresholdRequired && $activity->completion_threshold !== null)) {
                $this->add($issues, 'activity_completion', 'content', $context, $fragment);
            }
            if (($type === 'embedded_video' && ! $this->validEmbeddedActivityUrl($activity->external_video_url))
                || ($type !== 'embedded_video' && $activity->external_video_url !== null)
                || ($type === 'live_class' && ! $this->validHttpsUrl($activity->live_class_url))
                || ($type !== 'live_class' && $activity->live_class_url !== null)
                || ($type === 'quiz' && ! $this->positiveInteger($activity->assessment_quiz_id))
                || ($type !== 'quiz' && $activity->assessment_quiz_id !== null)) {
                $this->add($issues, 'activity_source', 'content', $context, $fragment);
            }
            if ($type === 'quiz') {
                $this->add($issues, 'activity_quiz_unavailable', 'content', $context, $fragment);
            }

            $activityMedia = $mediaByActivity->get($activity->id, collect());
            $activeMedia = $activityMedia->where('usage_status', 'active');
            $invalidMedia = false;
            foreach ($activeMedia as $media) {
                $expectedType = in_array($type, ['video', 'audio', 'document'], true) ? $type : 'attachment';
                if ((int) $media->usage_customer_id !== $customerId
                    || ! $media->resolved_media_id
                    || (int) $media->media_customer_id !== $customerId
                    || $media->media_status !== 'ready'
                    || ($media->usage_type !== 'attachment'
                        && ($media->usage_type !== $expectedType
                            || $media->file_type !== $expectedType
                            || ! $this->mediaService->fileContentIsAllowed(
                                $expectedType,
                                (string) $media->mime_type,
                                $media->extension,
                            )))) {
                    $invalidMedia = true;
                }
            }
            if (in_array($type, ['video', 'audio', 'document'], true)) {
                $correctActiveUsages = $activeMedia
                    ->where('usage_customer_id', $customerId)
                    ->where('usage_type', $type);
                $invalidMedia = $invalidMedia || $correctActiveUsages->count() !== 1;
            } elseif ($activeMedia->whereIn('usage_type', ['video', 'audio', 'document'])->isNotEmpty()) {
                $invalidMedia = true;
            }
            if ($invalidMedia) {
                $this->add($issues, 'activity_media', 'content', $context, $fragment);
            }

            $rule = $activity->unlock_rule;
            $prerequisite = $activity->unlock_after_activity_id ? (int) $activity->unlock_after_activity_id : null;
            $target = $prerequisite ? $map->get($prerequisite) : null;
            if (! in_array($rule, self::ACTIVITY_UNLOCK_RULES, true)
                || ($rule === 'none' && ($prerequisite || $activity->unlock_at))
                || ($rule === 'previous_activity_completed' && (! $target || $activity->unlock_at || (int) $target->template_lesson_id !== (int) $activity->template_lesson_id))) {
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
            $media = DB::table('media_files')
                ->where('customer_id', $customerId)
                ->where('id', $mediaId)
                ->first();
            $activeSlotUsages = DB::table('media_file_usages')
                ->where('owner_type', 'course_template')
                ->where('owner_id', $templateId)
                ->where('usage_type', $usageType)
                ->where('status', 'active')
                ->get();
            $valid = $media
                && $media->status === 'ready'
                && $media->file_type === $fileType
                && $this->mediaService->fileContentIsAllowed(
                    $fileType,
                    (string) $media->mime_type,
                    $media->extension,
                )
                && $activeSlotUsages->count() === 1
                && (int) $activeSlotUsages->first()->customer_id === $customerId
                && (int) $activeSlotUsages->first()->media_file_id === (int) $mediaId;
            if (! $valid) {
                $this->add(
                    $issues,
                    'template_'.$usageType,
                    'information',
                    ['slot' => $usageType],
                    $this->informationFragment($usageType),
                );
            }
        }
    }

    private function validateVideoState(object $template, Collection $issues): void
    {
        $valid = match ($template->intro_video_source) {
            null => ! $template->intro_video_media_file_id && ! $template->intro_video_embed_url && ! $template->intro_video_provider,
            'upload' => (bool) $template->intro_video_media_file_id && ! $template->intro_video_embed_url && ! $template->intro_video_provider,
            'embed' => $this->validTrustedVideoState($template),
            default => false,
        };
        if (! $valid) {
            $this->add($issues, 'video_state', 'information', [], 'intro_video_source', 'lf.LF_course_template_invalid_video_state');
        }
    }

    private function validateTemplateInformation(
        int $customerId,
        object $template,
        Collection $lessons,
        Collection $issues,
        Collection $warnings,
    ): void {
        $category = $template->category_id
            ? DB::table('core_course_categories')
                ->where('id', $template->category_id)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
                ->first()
            : null;
        if (! $category
            || (int) $category->customer_id !== $customerId
            || (int) $template->customer_id !== $customerId
            || ! is_string($category->name)
            || blank(trim($category->name))) {
            $this->add($issues, 'template_category', 'information', [], 'category_id');
        } elseif ($category->status !== 'active') {
            $this->add($issues, 'template_category_inactive', 'information', [], 'category_id');
        }

        $this->validateRequiredText($template->title, 255, 'template_title', 'title', $issues);
        $this->validateRequiredText($template->publisher_name, 255, 'template_publisher', 'publisher_name', $issues);
        $this->validateNullableText($template->short_description, 500, 'template_short_description', 'short_description', $issues);
        $this->validateNullableText($template->description, null, 'template_description', 'description', $issues);
        $this->validateNullableText($template->meta_title, 255, 'template_meta_title', null, $issues);
        $this->validateNullableText($template->meta_description, 500, 'template_meta_description', null, $issues);
        $this->validateNullableText($template->meta_keywords, 500, 'template_meta_keywords', null, $issues);

        if ($template->difficulty_level !== null
            && ! in_array($template->difficulty_level, ['beginner', 'intermediate', 'advanced'], true)) {
            $this->add($issues, 'template_difficulty', 'information', [], 'difficulty_level');
        }
        foreach ([
            ['estimated_minutes_per_lesson', 'template_estimated_minutes'],
            ['estimated_lesson_count', 'template_estimated_lesson_count'],
        ] as [$field, $code]) {
            if ($template->{$field} !== null && ! $this->canonicalPositiveInteger($template->{$field})) {
                $this->add($issues, $code, 'information', [], $field);
            }
        }

        if ($this->canonicalPositiveInteger($template->estimated_lesson_count)
            && $template->estimated_lesson_count !== $lessons->count()) {
            $this->add($warnings, 'template_estimated_lesson_count_mismatch', 'information', [
                'expected' => $template->estimated_lesson_count,
                'actual' => $lessons->count(),
            ], 'estimated_lesson_count');
        }
    }

    private function validateRequiredText(mixed $value, int $max, string $code, string $fragment, Collection $issues): void
    {
        if (! is_string($value) || blank(trim($value)) || mb_strlen($value) > $max) {
            $this->add($issues, $code, 'information', [], $fragment);
        }
    }

    private function validateNullableText(mixed $value, ?int $max, string $code, ?string $fragment, Collection $issues): void
    {
        if ($value !== null && (! is_string($value) || ($max !== null && mb_strlen($value) > $max))) {
            $this->add($issues, $code, 'information', [], $fragment);
        }
    }

    private function validTrustedVideoState(object $template): bool
    {
        if ($template->intro_video_media_file_id
            || ! is_string($template->intro_video_embed_url)
            || ! is_string($template->intro_video_provider)) {
            return false;
        }

        try {
            $normalized = $this->trustedVideoUrls->normalize($template->intro_video_embed_url);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $normalized['url'] === $template->intro_video_embed_url
            && $normalized['provider'] === $template->intro_video_provider;
    }

    private function canonicalPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value >= 1;
    }

    private function validRequiredText(mixed $value, int $max): bool
    {
        return is_string($value) && ! blank(trim($value)) && mb_strlen($value) <= $max;
    }

    private function canonicalBoolean(mixed $value): bool
    {
        return is_int($value) && in_array($value, [0, 1], true);
    }

    private function validEmbeddedActivityUrl(mixed $url): bool
    {
        if (! is_string($url)) {
            return false;
        }

        try {
            $normalized = $this->trustedVideoUrls->normalize($url);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $normalized['url'] === $url;
    }

    private function validHttpsUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https';
    }

    private function informationFragment(string $usageType): string
    {
        return match ($usageType) {
            'intro_image' => 'intro_image_file',
            'intro_video' => 'intro_video_source',
            'intro_document' => 'intro_document_file',
        };
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
            $code === 'activity_quiz_unavailable' && isset($context['activity']) => 'lf.LF_course_template_publish_integrity_activity_quiz_unavailable',
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
}
