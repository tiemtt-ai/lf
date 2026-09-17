<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\AuthoringProposalProvider;
use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Models\User;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\AiAuthoringProposalService;
use App\Services\CourseTemplateLearningMappingIntentService;
use App\Services\LearningFrameworkAuthoringService;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Real Course rows, a published Learning graph selected by the Template, and a
 * document Media File processed by the fake Media OCR provider — the minimum a
 * Step 7 service needs, built through the owner services wherever one exists.
 */
trait AuthoringMariaDbFixture
{
    /** @var array<string,mixed> */
    protected array $f;

    protected FakeTenantSettings $settings;

    protected FakeCommercialEntitlements $entitlements;

    protected FakeAuthoringProposalProvider $provider;

    protected function setUpAuthoring(): void
    {
        Storage::fake('media_local');
        config([
            'media.disk' => 'media_local', 'media.bucket' => 'test-media',
            'ai.providers' => ['approved-provider' => [
                'managed' => false, 'models' => ['authoring-model'], 'purposes' => ['authoring_proposal'],
                'regions' => ['lf_managed'], 'retention_classes' => ['none', 'transient'], 'data_classes' => ['derived_text'],
            ]],
            'ai.authoring.provider' => 'approved-provider',
            'ai.authoring.model' => 'authoring-model',
        ]);

        $this->settings = new FakeTenantSettings;
        $this->entitlements = new FakeCommercialEntitlements;
        $this->provider = new FakeAuthoringProposalProvider;
        $this->app->instance(TenantSettingSource::class, $this->settings);
        $this->app->bind(ExternalProcessingApprovals::class, SettingBackedExternalProcessingApprovals::class);
        $this->app->instance(CommercialEntitlements::class, $this->entitlements);
        $this->app->instance(UsageQuotaReserver::class, new FakeUsageQuotaReserver(100.0));
        $this->app->instance(AuthoringProposalProvider::class, $this->provider);

        $this->f = $this->authoringFixture('a');
    }

    protected function tenantService(string $class): object
    {
        TenantContext::set((object) ['id' => $this->f['customer_id']]);

        return $this->app->make($class);
    }

    protected function approveProvider(): void
    {
        $this->settings->approve($this->f['customer_id'], 'ai.external_processing.approved-provider.authoring_proposal', [
            'approved' => true, 'data_classes' => ['derived_text'],
            'execution_regions' => ['lf_managed'], 'retention_classes' => ['none'],
        ]);
        $this->entitlements->grant($this->f['customer_id'], 'ai_authoring_proposal');
    }

    /** @param array<int,string> $kinds @return array<string,mixed> */
    protected function generateAs(int $actorId, array $kinds, ?string $uuid = null): array
    {
        $basis = array_intersect($kinds, ['node_mapping', 'competency']) !== [];

        return $this->tenantService(AiAuthoringProposalService::class)->generate(
            $actorId, $this->f['activity_id'], $kinds,
            $basis ? $this->f['framework_id'] : null, $basis ? $this->f['version_id'] : null,
            $uuid ?? (string) Str::uuid(),
        );
    }

    /** @return array<string,mixed> */
    protected function authoringFixture(string $slug): array
    {
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => 'authoring-'.$slug, 'subdomain' => 'authoring-'.$slug, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $user = fn (string $role, string $name): User => User::forceCreate([
            'customer_id' => $customerId, 'name' => $name, 'email' => "{$name}-{$slug}@example.test",
            'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => $now,
        ]);
        $admin = $user('customer_admin', 'admin');
        $teacher = $user('teacher', 'teacher');
        $outsider = $user('teacher', 'outsider');
        TenantContext::set((object) ['id' => $customerId]);

        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'General '.$slug, 'slug' => 'general-'.$slug,
            'description' => null, 'thumbnail_image' => null, 'banner_image' => null, 'sort_order' => 1,
            'is_featured' => false, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'category_id' => $categoryId, 'title' => 'Template '.$slug,
            'short_description' => 'Course', 'description' => 'Course description.', 'publisher_name' => 'LearnForge',
            'intro_video_source' => null, 'intro_image_media_file_id' => null, 'intro_video_media_file_id' => null,
            'difficulty_level' => 'beginner', 'estimated_minutes_per_lesson' => 30, 'estimated_lesson_count' => null,
            'lesson_count' => 1, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'working_revision' => 1, 'status' => 'active', 'created_by' => $admin->id, 'last_version_published_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_course_template_teachers')->insert([
            'customer_id' => $customerId, 'template_id' => $templateId, 'teacher_id' => $teacher->id, 'role' => 'primary',
            'sort_order' => 0, 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $lessonId = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_section_id' => null, 'title' => 'Lesson',
            'short_description' => null, 'description' => null, 'sort_order' => 0, 'is_preview' => false,
            'duration_seconds' => 0, 'activity_count' => 1, 'unlock_rule' => 'none', 'unlock_after_lesson_id' => null,
            'unlock_at' => null, 'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_lesson_id' => $lessonId,
            'title' => 'Đọc tài liệu', 'description' => 'Đọc và ghi chú.', 'sort_order' => 0, 'activity_type' => 'document',
            'external_video_url' => null, 'live_class_url' => null, 'assessment_quiz_id' => null, 'duration_seconds' => 600,
            'is_required' => true, 'completion_rule' => 'view', 'completion_threshold' => null, 'is_preview' => false,
            'unlock_rule' => 'none', 'unlock_after_activity_id' => null, 'unlock_at' => null, 'created_by' => $admin->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $media = app(MediaService::class)->upload(UploadedFile::fake()->createWithContent('lesson.txt', 'Nội dung bài học về phân số.'), [
            'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => $activityId, 'purpose' => 'document',
        ], $admin->id);
        DB::table('media_file_usages')->insert([
            'customer_id' => $customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'document', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($customerId, $media->id, 'vi', $admin->id);

        $authoring = app(LearningFrameworkAuthoringService::class);
        $framework = $authoring->createFramework($admin->id, [
            'code' => 'fw-'.$slug, 'name' => 'Framework '.$slug, 'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [['key' => 'novice', 'threshold' => 0], ['key' => 'mastered', 'threshold' => 0.8]]],
        ]);
        $definition = $authoring->createDefinition($admin->id, [
            'framework_id' => $framework->id, 'code' => 'D-'.$slug, 'node_type' => 'competency', 'canonical_name' => 'Phân số',
        ]);
        $version = $authoring->createDraftVersion($admin->id, ['framework_id' => $framework->id, 'version_code' => 'v1', 'title' => 'V1']);
        $node = $authoring->createNode($admin->id, ['framework_version_id' => $version->id, 'node_definition_id' => $definition->id]);
        $authoring->publishVersion($admin->id, (int) $version->id);
        app(CourseTemplateLearningMappingIntentService::class)->select($admin->id, $customerId, $templateId, (int) $framework->id, (int) $version->id);

        return [
            'customer_id' => $customerId, 'admin_id' => (int) $admin->id, 'teacher_id' => (int) $teacher->id,
            'outsider_id' => (int) $outsider->id, 'template_id' => $templateId, 'lesson_id' => $lessonId, 'activity_id' => $activityId,
            'media_id' => (int) $media->id, 'framework_id' => (int) $framework->id, 'version_id' => (int) $version->id,
            'definition_id' => (int) $definition->id, 'node_id' => (int) $node->id,
        ];
    }
}
