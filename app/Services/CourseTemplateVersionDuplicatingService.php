<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CourseTemplateVersionDuplicatingService
{
    private const ACTIVITY_REFERENCE_TABLES = [
        'media_videos',
        'media_audios',
        'media_documents',
        'core_assessment_quizzes',
        'core_liveclass_rooms',
    ];

    public function duplicateToDraft(
        int $customerId,
        int $templateId,
        int $versionId,
        int $actorId,
        ?string $ipAddress
    ): object {
        return DB::transaction(function () use (
            $customerId,
            $templateId,
            $versionId,
            $actorId,
            $ipAddress
        ): object {
            $template = DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->lockForUpdate()
                ->first();

            abort_if(! $template, 404);

            $version = DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $versionId)
                ->whereIn('status', ['published', 'deprecated', 'archived'])
                ->lockForUpdate()
                ->first();

            abort_if(! $version, 404);

            $sections = $this->versionSections($customerId, $versionId);
            $lessons = $this->versionLessons($customerId, $versionId);
            $activities = $this->versionActivities($customerId, $versionId);

            $this->validateSnapshotGraph(
                $sections,
                $lessons,
                $activities
            );
            $this->assertTemplateSlugAvailable(
                $customerId,
                $templateId,
                $version->slug_snapshot
            );

            $beforeCounts = $this->draftCounts($customerId, $templateId);
            $now = now();

            $this->deleteDraftContent($customerId, $templateId);

            DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->update([
                    'category_id' => $this->categoryId(
                        $customerId,
                        $version->source_category_id
                    ),
                    'title' => $version->title_snapshot,
                    'slug' => $version->slug_snapshot,
                    'short_description' => $version
                        ->short_description_snapshot,
                    'description' => $version->description_snapshot,
                    'publisher_name' => $version->publisher_name_snapshot,
                    'cover_type' => $version->cover_type_snapshot,
                    'cover_image_media_file_id' => $this->referenceId(
                        $customerId,
                        $version->cover_image_media_file_id_snapshot,
                        ['media_files']
                    ),
                    'intro_video_media_file_id' => $this->referenceId(
                        $customerId,
                        $version->intro_video_media_file_id_snapshot,
                        ['media_files']
                    ),
                    'difficulty_level' => $version
                        ->difficulty_level_snapshot,
                    'estimated_duration_minutes' => $version
                        ->estimated_duration_minutes_snapshot,
                    'max_lessons' => $version->max_lessons_snapshot,
                    'lesson_count' => $lessons->count(),
                    'meta_title' => $version->meta_title_snapshot,
                    'meta_description' => $version
                        ->meta_description_snapshot,
                    'meta_keywords' => $version->meta_keywords_snapshot,
                    'working_revision' => (int) $template
                        ->working_revision + 1,
                    'status' => 'draft',
                    'updated_at' => $now,
                ]);

            $sectionMap = $this->restoreSections(
                $customerId,
                $templateId,
                $sections,
                $now
            );
            $lessonMap = $this->restoreLessons(
                $customerId,
                $templateId,
                $lessons,
                $sectionMap,
                $now
            );
            $this->restoreActivities(
                $customerId,
                $templateId,
                $activities,
                $lessonMap,
                $now
            );

            DB::table('saas_audit_logs')->insert([
                'customer_id' => $customerId,
                'actor_id' => $actorId,
                'target_user_id' => null,
                'action' => 'course_template_version_duplicated_to_draft',
                'before' => json_encode([
                    'template_id' => $templateId,
                    'working_revision' => (int) $template->working_revision,
                    'status' => $template->status,
                    'content_counts' => $beforeCounts,
                ], JSON_THROW_ON_ERROR),
                'after' => json_encode([
                    'template_id' => $templateId,
                    'source_template_version_id' => $versionId,
                    'working_revision' => (int) $template
                        ->working_revision + 1,
                    'status' => 'draft',
                    'content_counts' => [
                        'sections' => $sections->count(),
                        'lessons' => $lessons->count(),
                        'activities' => $activities->count(),
                    ],
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);

            return DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->first();
        });
    }

    private function versionSections(
        int $customerId,
        int $versionId
    ): Collection {
        return DB::table('core_course_template_version_sections')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->orderBy('parent_version_section_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    private function versionLessons(
        int $customerId,
        int $versionId
    ): Collection {
        return DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->orderByRaw('version_section_id IS NOT NULL')
            ->orderBy('version_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function versionActivities(
        int $customerId,
        int $versionId
    ): Collection {
        return DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->orderBy('version_lesson_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function validateSnapshotGraph(
        Collection $sections,
        Collection $lessons,
        Collection $activities
    ): void {
        $sectionIds = $sections->pluck('id')->flip();
        $lessonIds = $lessons->pluck('id')->flip();
        $activityIds = $activities->pluck('id')->flip();
        $activitiesById = $activities->keyBy('id');

        foreach ($sections as $section) {
            if (
                $section->parent_version_section_id
                && ! $sectionIds->has($section->parent_version_section_id)
            ) {
                $this->invalidStructure();
            }
        }

        $this->assertAcyclicSections($sections);

        $this->assertUniqueOrder(
            $lessons,
            fn (object $lesson): string => (string) (
                $lesson->version_section_id ?? 'direct'
            )
        );
        $this->assertUniqueOrder(
            $activities,
            fn (object $activity): string => (string) (
                $activity->version_lesson_id
            )
        );

        foreach ($lessons as $lesson) {
            if (
                $lesson->version_section_id
                && ! $sectionIds->has($lesson->version_section_id)
            ) {
                $this->invalidStructure();
            }
            if ($lesson->version_section_id) {
                $section = $sections->firstWhere('id', $lesson->version_section_id);
                if (! $section || ! $section->allows_lessons) {
                    $this->invalidStructure();
                }
            }

            if (
                $lesson->unlock_after_version_lesson_id
                && ! $lessonIds->has(
                    $lesson->unlock_after_version_lesson_id
                )
            ) {
                $this->invalidStructure();
            }
        }

        foreach ($activities as $activity) {
            if (! $lessonIds->has($activity->version_lesson_id)) {
                $this->invalidStructure();
            }

            if (
                $activity->unlock_after_version_activity_id
                && ! $activityIds->has(
                    $activity->unlock_after_version_activity_id
                )
            ) {
                $this->invalidStructure();
            }

            if (
                $activity->unlock_after_version_activity_id
                && $activitiesById[
                    $activity->unlock_after_version_activity_id
                ]->version_lesson_id !== $activity->version_lesson_id
            ) {
                $this->invalidStructure();
            }
        }
    }

    private function assertUniqueOrder(
        Collection $records,
        callable $groupKey
    ): void {
        $seen = [];

        foreach ($records as $record) {
            $order = $record->display_order ?? $record->sort_order;
            $key = $groupKey($record).':'.$order;

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'duplicate' => __(
                        'lf.LF_course_template_duplicate_invalid_order'
                    ),
                ]);
            }

            $seen[$key] = true;
        }
    }

    private function assertAcyclicSections(Collection $sections): void
    {
        $parents = $sections
            ->pluck('parent_version_section_id', 'id')
            ->map(fn ($parentId): ?int => $parentId === null
                ? null
                : (int) $parentId)
            ->all();

        foreach (array_keys($parents) as $sectionId) {
            $seen = [];
            $currentId = (int) $sectionId;

            while ($parents[$currentId] ?? null) {
                if (isset($seen[$currentId])) {
                    $this->invalidStructure();
                }

                $seen[$currentId] = true;
                $currentId = $parents[$currentId];
            }
        }
    }

    private function assertTemplateSlugAvailable(
        int $customerId,
        int $templateId,
        string $slug
    ): void {
        if (
            DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('slug', $slug)
                ->where('id', '!=', $templateId)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'duplicate' => __(
                    'lf.LF_course_template_duplicate_slug_conflict'
                ),
            ]);
        }
    }

    private function deleteDraftContent(
        int $customerId,
        int $templateId
    ): void {
        DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->update(['unlock_after_activity_id' => null]);

        DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->delete();

        DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->update(['unlock_after_lesson_id' => null]);

        DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->delete();

        DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->update(['parent_section_id' => null]);

        DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->delete();
    }

    private function restoreSections(
        int $customerId,
        int $templateId,
        Collection $sections,
        $now
    ): array {
        $map = [];
        $pending = $sections->keyBy('id');

        while ($pending->isNotEmpty()) {
            $inserted = 0;

            foreach ($pending as $versionSectionId => $section) {
                if (
                    $section->parent_version_section_id
                    && ! isset($map[$section->parent_version_section_id])
                ) {
                    continue;
                }

                $map[$versionSectionId] = DB::table(
                    'core_course_template_sections'
                )->insertGetId([
                    'customer_id' => $customerId,
                    'template_id' => $templateId,
                    'parent_section_id' => $section->parent_version_section_id
                        ? $map[$section->parent_version_section_id]
                        : null,
                    'allows_lessons' => (bool) $section->allows_lessons,
                    'title' => $section->title_snapshot,
                    'description' => $section->description_snapshot,
                    'display_order' => $section->display_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $pending->forget($versionSectionId);
                $inserted++;
            }

            if ($inserted === 0) {
                $this->invalidStructure();
            }
        }

        return $map;
    }

    private function restoreLessons(
        int $customerId,
        int $templateId,
        Collection $lessons,
        array $sectionMap,
        $now
    ): array {
        $map = [];

        foreach ($lessons as $lesson) {
            $map[$lesson->id] = DB::table(
                'core_course_template_lessons'
            )->insertGetId([
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'template_section_id' => $lesson->version_section_id
                    ? $sectionMap[$lesson->version_section_id]
                    : null,
                'title' => $lesson->title_snapshot,
                'slug' => $lesson->slug_snapshot,
                'short_description' => $lesson
                    ->short_description_snapshot,
                'description' => $lesson->description_snapshot,
                'sort_order' => $lesson->sort_order,
                'is_preview' => $lesson->is_preview,
                'learning_objective' => $lesson
                    ->learning_objective_snapshot,
                'duration_seconds' => $lesson->duration_seconds,
                'activity_count' => $lesson->activity_count,
                'unlock_rule' => $lesson->unlock_rule_snapshot,
                'unlock_after_lesson_id' => null,
                'unlock_at' => $lesson->unlock_at_snapshot,
                'status' => $lesson->status_snapshot,
                'created_by' => $lesson->created_by_snapshot,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($lessons as $lesson) {
            if (! $lesson->unlock_after_version_lesson_id) {
                continue;
            }

            DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $map[$lesson->id])
                ->update([
                    'unlock_after_lesson_id' => $map[
                        $lesson->unlock_after_version_lesson_id
                    ],
                    'updated_at' => $now,
                ]);
        }

        return $map;
    }

    private function restoreActivities(
        int $customerId,
        int $templateId,
        Collection $activities,
        array $lessonMap,
        $now
    ): void {
        $map = [];

        foreach ($activities as $activity) {
            [$referenceType, $referenceId] = $this->activityReference(
                $customerId,
                $activity->activity_ref_type_snapshot,
                $activity->activity_ref_id_snapshot
            );

            $map[$activity->id] = DB::table(
                'core_course_template_activities'
            )->insertGetId([
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'template_lesson_id' => $lessonMap[
                    $activity->version_lesson_id
                ],
                'title' => $activity->title_snapshot,
                'description' => $activity->description_snapshot,
                'sort_order' => $activity->sort_order,
                'activity_type' => $activity->activity_type,
                'activity_ref_type' => $referenceType,
                'activity_ref_id' => $referenceId,
                'external_url' => $activity->external_url_snapshot,
                'embed_code' => $activity->embed_code_snapshot,
                'duration_seconds' => $activity->duration_seconds,
                'is_required' => $activity->is_required,
                'completion_rule' => $activity->completion_rule,
                'completion_threshold' => $activity
                    ->completion_threshold,
                'is_preview' => $activity->is_preview,
                'unlock_rule' => $activity->unlock_rule_snapshot,
                'unlock_after_activity_id' => null,
                'unlock_at' => $activity->unlock_at_snapshot,
                'status' => $activity->status_snapshot,
                'created_by' => $activity->created_by_snapshot,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($activities as $activity) {
            if (! $activity->unlock_after_version_activity_id) {
                continue;
            }

            DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $map[$activity->id])
                ->update([
                    'unlock_after_activity_id' => $map[
                        $activity->unlock_after_version_activity_id
                    ],
                    'updated_at' => $now,
                ]);
        }
    }

    private function categoryId(
        int $customerId,
        ?int $categoryId
    ): ?int {
        if (! $categoryId) {
            return null;
        }

        return DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->where('id', $categoryId)
            ->exists()
                ? $categoryId
                : null;
    }

    private function activityReference(
        int $customerId,
        ?string $referenceType,
        ?int $referenceId
    ): array {
        if (
            ! $referenceType
            || ! $referenceId
            || ! in_array(
                $referenceType,
                self::ACTIVITY_REFERENCE_TABLES,
                true
            )
        ) {
            return [null, null];
        }

        $resolvedId = $this->referenceId(
            $customerId,
            $referenceId,
            [$referenceType]
        );

        return $resolvedId
            ? [$referenceType, $resolvedId]
            : [null, null];
    }

    private function referenceId(
        int $customerId,
        ?int $referenceId,
        array $tables
    ): ?int {
        if (! $referenceId) {
            return null;
        }

        foreach ($tables as $table) {
            if (
                Schema::hasTable($table)
                && Schema::hasColumn($table, 'customer_id')
                && DB::table($table)
                    ->where('customer_id', $customerId)
                    ->where('id', $referenceId)
                    ->exists()
            ) {
                return $referenceId;
            }
        }

        return null;
    }

    private function draftCounts(
        int $customerId,
        int $templateId
    ): array {
        return [
            'sections' => DB::table('core_course_template_sections')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->count(),
            'lessons' => DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->count(),
            'activities' => DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->count(),
        ];
    }

    private function invalidStructure(): never
    {
        throw ValidationException::withMessages([
            'duplicate' => __(
                'lf.LF_course_template_duplicate_invalid_structure'
            ),
        ]);
    }
}
