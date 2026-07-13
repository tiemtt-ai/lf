<?php

namespace App\Services;

use App\Support\SequentialCodeGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseTemplatePublishingService
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly CourseTemplatePublishReadinessService $readinessService,
    ) {}

    public function publish(
        int $customerId,
        int $templateId,
        int $publishedBy
    ): object {
        return DB::transaction(function () use (
            $customerId,
            $templateId,
            $publishedBy
        ): object {
            $graph = $this->readinessService->load($customerId, $templateId, true);
            $template = $graph->template;
            $sections = $graph->sections;
            $lessons = $graph->lessons;
            $activities = $graph->activities;
            $this->readinessService->evaluate($customerId, $graph)->assertReady();

            $categoryName = $template->category_id
                ? DB::table('core_course_categories')
                    ->where('customer_id', $customerId)
                    ->where('id', $template->category_id)
                    ->value('name')
                : null;

            $versionNumber = (int) DB::table(
                'core_course_template_versions'
            )
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->max('version_number') + 1;
            $now = now();
            $versionId = DB::table(
                'core_course_template_versions'
            )->insertGetId([
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'version_number' => $versionNumber,
                'version_code' => SequentialCodeGenerator::next(
                    $customerId,
                    'core_course_template_versions',
                    'version_code',
                    'VER'
                ),
                'is_current' => false,
                'source_category_id' => $template->category_id,
                'category_name_snapshot' => $categoryName,
                'title_snapshot' => $template->title,
                'short_description_snapshot' => $template->short_description,
                'description_snapshot' => $template->description,
                'publisher_name_snapshot' => $template->publisher_name,
                'intro_image_media_file_id_snapshot' => $template->intro_image_media_file_id,
                'intro_video_source_snapshot' => $template->intro_video_source,
                'intro_video_media_file_id_snapshot' => $template
                    ->intro_video_media_file_id,
                'intro_video_embed_url_snapshot' => $template->intro_video_embed_url,
                'intro_video_provider_snapshot' => $template->intro_video_provider,
                'intro_document_media_file_id_snapshot' => $template->intro_document_media_file_id,
                'difficulty_level_snapshot' => $template->difficulty_level,
                'estimated_minutes_per_lesson_snapshot' => $template->estimated_minutes_per_lesson,
                'estimated_lesson_count_snapshot' => $template->estimated_lesson_count,
                'lesson_count_snapshot' => $lessons->count(),
                'meta_title_snapshot' => $template->meta_title,
                'meta_description_snapshot' => $template->meta_description,
                'meta_keywords_snapshot' => $template->meta_keywords,
                'source_working_revision' => $template->working_revision,
                'status' => 'draft_snapshot',
                'published_at' => null,
                'published_by' => $publishedBy,
                'source_template_updated_at' => $template->updated_at,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['intro_image' => $template->intro_image_media_file_id, 'intro_video' => $template->intro_video_media_file_id, 'intro_document' => $template->intro_document_media_file_id] as $usage => $mediaId) {
                if ($mediaId) {
                    $this->mediaService->attachUsage((int) $mediaId, 'course_template_version', $versionId, $usage);
                }
            }

            $sectionMap = $this->snapshotSections(
                $customerId,
                $versionId,
                $sections,
                $now
            );
            $lessonMap = $this->snapshotLessons(
                $customerId,
                $versionId,
                $lessons,
                $activities,
                $sections,
                $sectionMap,
                $now
            );
            $this->snapshotActivities(
                $customerId,
                $versionId,
                $activities,
                $lessonMap,
                $now
            );

            DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'updated_at' => $now,
                ]);

            DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $versionId)
                ->update([
                    'is_current' => true,
                    'status' => 'published',
                    'published_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->update(['last_version_published_at' => $now]);

            return DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $versionId)
                ->first();
        });
    }

    private function snapshotSections(
        int $customerId,
        int $versionId,
        Collection $sections,
        $now
    ): array {
        $map = [];
        $pending = $sections->keyBy('id');

        while ($pending->isNotEmpty()) {
            $inserted = 0;

            foreach ($pending as $sectionId => $section) {
                if (
                    $section->parent_section_id
                    && ! isset($map[$section->parent_section_id])
                ) {
                    continue;
                }

                $map[$sectionId] = DB::table(
                    'core_course_template_version_sections'
                )->insertGetId([
                    'customer_id' => $customerId,
                    'template_version_id' => $versionId,
                    'source_template_section_id' => $section->id,
                    'parent_version_section_id' => $section->parent_section_id
                        ? $map[$section->parent_section_id]
                        : null,
                    'allows_lessons' => (bool) $section->allows_lessons,
                    'title_snapshot' => $section->title,
                    'description_snapshot' => $section->description,
                    'display_order' => $section->display_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $pending->forget($sectionId);
                $inserted++;
            }

            if ($inserted === 0) {
                throw ValidationException::withMessages([
                    'publish' => __(
                        'lf.LF_course_template_publish_invalid_structure'
                    ),
                ]);
            }
        }

        return $map;
    }

    private function snapshotLessons(
        int $customerId,
        int $versionId,
        Collection $lessons,
        Collection $activities,
        Collection $sections,
        array $sectionMap,
        $now
    ): array {
        $map = [];

        foreach ($lessons as $lesson) {
            if ($lesson->template_section_id) {
                $this->assertMapped(
                    $sectionMap,
                    $lesson->template_section_id,
                    'publish'
                );

                $section = $sections->firstWhere('id', $lesson->template_section_id);
                if (! $section || ! $section->allows_lessons) {
                    throw ValidationException::withMessages([
                        'publish' => __('lf.LF_course_template_publish_invalid_structure'),
                    ]);
                }
            }

            $map[$lesson->id] = DB::table(
                'core_course_template_version_lessons'
            )->insertGetId([
                'customer_id' => $customerId,
                'template_version_id' => $versionId,
                'version_section_id' => $lesson->template_section_id
                    ? $sectionMap[$lesson->template_section_id]
                    : null,
                'source_template_lesson_id' => $lesson->id,
                'title_snapshot' => $lesson->title,
                'short_description_snapshot' => $lesson->short_description,
                'description_snapshot' => $lesson->description,
                'sort_order' => $lesson->sort_order,
                'is_preview' => $lesson->is_preview,
                'lesson_type' => $lesson->lesson_type ?? 'regular',
                'duration_seconds' => $lesson->duration_seconds,
                'activity_count' => $activities
                    ->where('template_lesson_id', $lesson->id)
                    ->count(),
                'unlock_rule_snapshot' => $lesson->unlock_rule,
                'unlock_after_version_lesson_id' => null,
                'unlock_at_snapshot' => $lesson->unlock_at,
                'created_by_snapshot' => $lesson->created_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($lessons as $lesson) {
            if (! $lesson->unlock_after_lesson_id) {
                continue;
            }

            $this->assertMapped(
                $map,
                $lesson->unlock_after_lesson_id,
                'publish'
            );

            DB::table('core_course_template_version_lessons')
                ->where('customer_id', $customerId)
                ->where('template_version_id', $versionId)
                ->where('id', $map[$lesson->id])
                ->update([
                    'unlock_after_version_lesson_id' => $map[
                        $lesson->unlock_after_lesson_id
                    ],
                    'updated_at' => $now,
                ]);
        }

        return $map;
    }

    private function snapshotActivities(
        int $customerId,
        int $versionId,
        Collection $activities,
        array $lessonMap,
        $now
    ): void {
        $map = [];
        $uploadedTypes = ['video', 'audio', 'document'];
        $mediaByActivity = DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('owner_type', 'course_activity')
            ->where('status', 'active')
            ->whereIn('owner_id', $activities->pluck('id'))
            ->whereIn('usage_type', $uploadedTypes)
            ->get()->groupBy('owner_id');

        foreach ($activities as $activity) {
            $this->assertMapped(
                $lessonMap,
                $activity->template_lesson_id,
                'publish'
            );

            $mediaUsage = in_array($activity->activity_type, $uploadedTypes, true)
                ? $mediaByActivity->get($activity->id, collect())->firstWhere('usage_type', $activity->activity_type)
                : null;
            if (in_array($activity->activity_type, $uploadedTypes, true) && ! $mediaUsage) {
                throw ValidationException::withMessages(['publish' => __('lf.LF_course_template_publish_integrity_activity_media')]);
            }

            $map[$activity->id] = DB::table(
                'core_course_template_version_activities'
            )->insertGetId([
                'customer_id' => $customerId,
                'template_version_id' => $versionId,
                'version_lesson_id' => $lessonMap[
                    $activity->template_lesson_id
                ],
                'source_template_activity_id' => $activity->id,
                'title_snapshot' => $activity->title,
                'description_snapshot' => $activity->description,
                'sort_order' => $activity->sort_order,
                'activity_type' => $activity->activity_type,
                'media_file_id' => $mediaUsage?->media_file_id,
                'external_video_url_snapshot' => $activity->external_video_url,
                'live_class_url_snapshot' => $activity->live_class_url,
                'assessment_quiz_id_snapshot' => $activity->assessment_quiz_id,
                'duration_seconds' => $activity->duration_seconds,
                'estimated_duration_seconds_snapshot' => $activity->estimated_duration_seconds,
                'is_required' => $activity->is_required,
                'completion_rule' => $activity->completion_rule,
                'completion_threshold' => $activity->completion_threshold,
                'is_preview' => $activity->is_preview,
                'unlock_rule_snapshot' => $activity->unlock_rule,
                'unlock_after_version_activity_id' => null,
                'unlock_at_snapshot' => $activity->unlock_at,
                'created_by_snapshot' => $activity->created_by,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($mediaUsage) {
                $this->mediaService->attachUsage((int) $mediaUsage->media_file_id, 'course_version_activity', $map[$activity->id], $activity->activity_type);
            }
        }

        foreach ($activities as $activity) {
            if (! $activity->unlock_after_activity_id) {
                continue;
            }

            $this->assertMapped(
                $map,
                $activity->unlock_after_activity_id,
                'publish'
            );

            DB::table('core_course_template_version_activities')
                ->where('customer_id', $customerId)
                ->where('template_version_id', $versionId)
                ->where('id', $map[$activity->id])
                ->update([
                    'unlock_after_version_activity_id' => $map[
                        $activity->unlock_after_activity_id
                    ],
                    'updated_at' => $now,
                ]);
        }
    }

    private function assertMapped(
        array $map,
        int $sourceId,
        string $field
    ): void {
        if (! isset($map[$sourceId])) {
            throw ValidationException::withMessages([
                $field => __(
                    'lf.LF_course_template_publish_invalid_structure'
                ),
            ]);
        }
    }
}
