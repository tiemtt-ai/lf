<?php

namespace App\Services;

use App\Support\SequentialCodeGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseTemplatePublishingService
{
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
            $template = DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->lockForUpdate()
                ->first();

            abort_if(! $template, 404);

            $categoryName = $template->category_id
                ? DB::table('core_course_categories')
                    ->where('customer_id', $customerId)
                    ->where('id', $template->category_id)
                    ->value('name')
                : null;

            $sections = $this->sourceSections($customerId, $templateId);
            $lessons = $this->sourceLessons($customerId, $templateId);
            $activities = $this->sourceActivities($customerId, $templateId);

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
                'slug_snapshot' => $template->slug,
                'short_description_snapshot' => $template->short_description,
                'description_snapshot' => $template->description,
                'publisher_name_snapshot' => $template->publisher_name,
                'cover_type_snapshot' => $template->cover_type,
                'cover_image_media_file_id_snapshot' => $template
                    ->cover_image_media_file_id,
                'intro_video_media_file_id_snapshot' => $template
                    ->intro_video_media_file_id,
                'difficulty_level_snapshot' => $template->difficulty_level,
                'estimated_duration_minutes_snapshot' => $template
                    ->estimated_duration_minutes,
                'max_lessons_snapshot' => $template->max_lessons,
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

            return DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $versionId)
                ->first();
        });
    }

    private function sourceSections(
        int $customerId,
        int $templateId
    ): Collection {
        return DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderBy('parent_section_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    private function sourceLessons(
        int $customerId,
        int $templateId
    ): Collection {
        return DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderByRaw('template_section_id IS NOT NULL')
            ->orderBy('template_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function sourceActivities(
        int $customerId,
        int $templateId
    ): Collection {
        return DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderBy('template_lesson_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
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
                'slug_snapshot' => $lesson->slug,
                'short_description_snapshot' => $lesson->short_description,
                'description_snapshot' => $lesson->description,
                'sort_order' => $lesson->sort_order,
                'is_preview' => $lesson->is_preview,
                'learning_objective_snapshot' => $lesson->learning_objective,
                'duration_seconds' => $lesson->duration_seconds,
                'activity_count' => $activities
                    ->where('template_lesson_id', $lesson->id)
                    ->count(),
                'unlock_rule_snapshot' => $lesson->unlock_rule,
                'unlock_after_version_lesson_id' => null,
                'unlock_at_snapshot' => $lesson->unlock_at,
                'status_snapshot' => $lesson->status,
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

        foreach ($activities as $activity) {
            $this->assertMapped(
                $lessonMap,
                $activity->template_lesson_id,
                'publish'
            );

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
                'activity_ref_type_snapshot' => $activity->activity_ref_type,
                'activity_ref_id_snapshot' => $activity->activity_ref_id,
                'external_url_snapshot' => $activity->external_url,
                'embed_code_snapshot' => $activity->embed_code,
                'duration_seconds' => $activity->duration_seconds,
                'is_required' => $activity->is_required,
                'completion_rule' => $activity->completion_rule,
                'completion_threshold' => $activity->completion_threshold,
                'is_preview' => $activity->is_preview,
                'unlock_rule_snapshot' => $activity->unlock_rule,
                'unlock_after_version_activity_id' => null,
                'unlock_at_snapshot' => $activity->unlock_at,
                'status_snapshot' => $activity->status,
                'created_by_snapshot' => $activity->created_by,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
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
