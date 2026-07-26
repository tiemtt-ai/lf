<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CourseTemplateVersionDetailPresenter
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly TrustedVideoUrlService $trustedVideos,
    ) {}

    public function present(int $versionId, Collection $lessons, Collection $activities): array
    {
        $lessonTitles = $lessons->pluck('title_snapshot', 'id');
        $activityTitles = $activities->pluck('title_snapshot', 'id');
        $activityLessons = $activities->pluck('version_lesson_id', 'id');
        $mediaUrls = $this->mediaService->generateVersionActivitySignedUrls($versionId, $activities->whereNotNull('media_file_id')->pluck('id')->all());
        $presentedActivities = $activities->mapWithKeys(function ($activity) use ($activityTitles, $activityLessons, $mediaUrls) {
            $minutes = $activity->estimated_duration_seconds_snapshot ? intdiv($activity->estimated_duration_seconds_snapshot, 60) : null;
            $availability = collect([
                'anytime' => $activity->available_anytime,
                'before_session' => $activity->available_before_session,
                'during_session' => $activity->available_during_session,
                'after_session' => $activity->available_after_session,
            ])->filter()->keys()->map(
                fn (string $phase): string => __('lf.LF_course_template_activity_learning_availability_'.$phase)
            )->implode(', ');
            $unlock = match ($activity->unlock_rule_snapshot) {
                'none' => __('lf.LF_version_detail_unlock_none'),
                'previous_activity_completed' => $activityTitles->has($activity->unlock_after_version_activity_id)
                    && $activityLessons->get($activity->unlock_after_version_activity_id) === $activity->version_lesson_id
                    ? __('lf.LF_version_detail_activity_after', ['title' => $activityTitles->get($activity->unlock_after_version_activity_id)])
                    : __('lf.LF_version_detail_activity_unavailable'),
                'date_based' => $activity->unlock_at_snapshot ? __('lf.LF_version_detail_available_from', ['datetime' => $this->date($activity->unlock_at_snapshot)]) : __('lf.LF_version_detail_unlock_invalid'),
                default => __('lf.LF_version_detail_unlock_invalid'),
            };
            $completion = match ($activity->completion_rule) {
                'view' => __('lf.LF_version_detail_completion_view'),
                'watch_percent' => __('lf.LF_version_detail_completion_watch', ['threshold' => $activity->completion_threshold]),
                'submit' => __('lf.LF_version_detail_completion_submit'),
                'pass' => __('lf.LF_version_detail_completion_pass', ['threshold' => $activity->completion_threshold]),
                'join' => __('lf.LF_version_detail_completion_join'),
                'manual' => __('lf.LF_version_detail_completion_manual'),
                default => __('lf.LF_version_detail_completion_invalid'),
            };
            $mediaUrl = $mediaUrls[$activity->id] ?? null;
            $embeddedUrl = null;
            if ($activity->activity_type === 'embedded_video'
                && $activity->external_video_url_snapshot) {
                try {
                    $embeddedUrl = $this->trustedVideos->embedUrl(
                        $activity->external_video_url_snapshot
                    );
                } catch (\Throwable) {
                    $embeddedUrl = null;
                }
            }

            return [$activity->id => compact(
                'activity',
                'minutes',
                'availability',
                'unlock',
                'completion',
                'mediaUrl',
                'embeddedUrl'
            )];
        });
        $presentedLessons = $lessons->mapWithKeys(function ($lesson) use ($lessonTitles) {
            $minutes = $lesson->duration_seconds ? intdiv($lesson->duration_seconds, 60) : null;
            $unlock = match ($lesson->unlock_rule_snapshot) {
                'none' => __('lf.LF_version_detail_unlock_none'),
                'previous_lesson_completed' => $lessonTitles->has($lesson->unlock_after_version_lesson_id)
                    ? __('lf.LF_version_detail_lesson_after', ['title' => $lessonTitles->get($lesson->unlock_after_version_lesson_id)])
                    : __('lf.LF_version_detail_lesson_unavailable'),
                'all_previous_lessons_completed' => __(
                    'lf.LF_version_detail_lesson_after_all_previous'
                ),
                'selected_lessons_completed' => __(
                    $lesson->prerequisite_match_snapshot === 'any'
                        ? 'lf.LF_version_detail_lesson_after_any_selected'
                        : 'lf.LF_version_detail_lesson_after_all_selected'
                ),
                'date_based' => $lesson->unlock_at_snapshot ? __('lf.LF_version_detail_available_from', ['datetime' => $this->date($lesson->unlock_at_snapshot)]) : __('lf.LF_version_detail_unlock_invalid'),
                default => __('lf.LF_version_detail_unlock_invalid'),
            };

            return [$lesson->id => compact('lesson', 'minutes', 'unlock')];
        });

        return compact('presentedLessons', 'presentedActivities');
    }

    private function date(string $value): string
    {
        return Carbon::parse($value)->timezone(config('app.timezone'))->format('d/m/Y H:i T');
    }
}
