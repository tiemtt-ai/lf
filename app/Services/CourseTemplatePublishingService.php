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
                'prerequisite_match_snapshot' => $lesson->prerequisite_match,
                'unlock_after_version_lesson_id' => null,
                'unlock_at_snapshot' => $lesson->unlock_at,
                'created_by_snapshot' => $lesson->created_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $selected = DB::table('core_course_template_lesson_prerequisites')
            ->where('customer_id', $customerId)
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->orderBy('sort_order')
            ->get()
            ->groupBy('lesson_id');
        $orderedIds = $this->orderedLessonIds($lessons, $sections);
        $positions = array_flip($orderedIds);

        foreach ($lessons as $lesson) {
            $prerequisiteIds = match ($lesson->unlock_rule) {
                'previous_lesson_completed' => array_filter([
                    (int) $lesson->unlock_after_lesson_id,
                ]),
                'selected_lessons_completed' => $selected
                    ->get($lesson->id, collect())
                    ->pluck('prerequisite_lesson_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
                'all_previous_lessons_completed' => array_values(array_filter(
                    $orderedIds,
                    fn (int $id): bool =>
                        $positions[$id] < $positions[$lesson->id]
                        && (($lessons->firstWhere('id', $id)->template_section_id === null)
                            === ($lesson->template_section_id === null))
                )),
                default => [],
            };

            foreach ($prerequisiteIds as $order => $prerequisiteId) {
                $this->assertMapped($map, $prerequisiteId, 'publish');
                if ($lesson->unlock_rule === 'previous_lesson_completed') {
                    DB::table('core_course_template_version_lessons')
                        ->where('customer_id', $customerId)
                        ->where('template_version_id', $versionId)
                        ->where('id', $map[$lesson->id])
                        ->update([
                            'unlock_after_version_lesson_id' => $map[
                                $prerequisiteId
                            ],
                            'updated_at' => $now,
                        ]);

                    continue;
                }
                DB::table(
                    'core_course_template_version_lesson_prerequisites'
                )->insert([
                    'customer_id' => $customerId,
                    'template_version_id' => $versionId,
                    'version_lesson_id' => $map[$lesson->id],
                    'prerequisite_version_lesson_id' => $map[$prerequisiteId],
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        return $map;
    }

    private function orderedLessonIds(
        Collection $lessons,
        Collection $sections
    ): array {
        $sectionKeys = [];
        $children = $sections->groupBy(
            fn (object $section): int => (int) ($section->parent_section_id ?? 0)
        );
        $walk = function (int $parentId, string $prefix = '') use (
            &$walk,
            &$sectionKeys,
            $children
        ): void {
            foreach ($children->get($parentId, collect())->sortBy(
                fn (object $section): string => sprintf(
                    '%010d:%020d',
                    $section->display_order,
                    $section->id
                )
            ) as $section) {
                $key = $prefix.sprintf(
                    '/S%010d:%020d',
                    $section->display_order,
                    $section->id
                );
                $sectionKeys[$section->id] = $key;
                $walk((int) $section->id, $key);
            }
        };
        $walk(0);

        return $lessons->sortBy(fn (object $lesson): string =>
            ($lesson->template_section_id === null
                ? '0'
                : '1'.($sectionKeys[$lesson->template_section_id] ?? ''))
            .sprintf('/L%010d:%020d', $lesson->sort_order, $lesson->id)
        )->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
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
                'available_anytime' => $activity->available_anytime,
                'available_before_session' => $activity->available_before_session,
                'available_during_session' => $activity->available_during_session,
                'available_after_session' => $activity->available_after_session,
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
