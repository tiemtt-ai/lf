<?php

namespace App\Console\Commands;

use App\Contracts\Ai\AuthoringProposalProvider;
use App\Contracts\Ai\TenantSettingSource;
use App\Models\User;
use App\Services\AiAuthoringProposalService;
use App\Services\CourseTemplateLearningMappingIntentService;
use App\Services\LearningFrameworkAuthoringService;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaService;
use App\Support\AiDemo\DemoAuthoringProposalProvider;
use App\Support\AiDemo\InMemoryTenantSettings;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Local-only demo data for the AI proposal review UI (design v0.5 §9, D5).
 *
 * Builds one synthetic tenant with a Course Template, a processed document, a
 * published Learning framework and a set of proposals in different states, so
 * the review UI can be seen and tested without any real model. Everything goes
 * through the same owner services and provider gate as production; only the
 * model is a fixed-text stand-in that never leaves the process.
 *
 * Safety, in the order it is enforced:
 *  - it refuses to run anywhere but the `local` and `testing` environments,
 *    before touching the database, and has no option to override that;
 *  - it refuses unless the media disk is a plain local directory, again before
 *    touching the database: an environment name says nothing about where files
 *    would go, and a remote disk (S3 and the like) would mean a network call
 *    with credentials from configuration;
 *  - the synthetic tenant's media folder is claimed with an atomic, exclusive
 *    `mkdir` and never written into if it already exists, so a tenant folder that
 *    belongs to another database on the same disk is left alone;
 *  - the provider, the tenant approval, the media providers and the queue are
 *    substituted for this process only. No allow-list, approval or credential is
 *    ever written, so a later process finds the gate exactly as it was;
 *  - only the synthetic tenant `ai-demo` is created; a second run finds it and
 *    stops without changing anything.
 *
 * The demo accounts use a fixed, obviously synthetic password that only works
 * for the synthetic tenant on a local database.
 */
class AiAuthoringDemoSeed extends Command
{
    public const SLUG = 'ai-demo';

    /** Synthetic and local-only; the accounts exist only in the synthetic tenant. */
    private const PASSWORD = 'ai-demo-local-only';

    protected $signature = 'ai:authoring-demo-seed';

    protected $description = 'Local/testing only: create a synthetic tenant with AI proposals in several states, using a stand-in model.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command only runs in the local and testing environments.');

            return self::FAILURE;
        }

        if ($this->mediaRoot() === null) {
            $this->error('This command only writes media to a local disk. The configured media disk is not one (or has no root).');

            return self::FAILURE;
        }

        if (DB::table('saas_customers')->where('slug', self::SLUG)->exists()) {
            $this->info('The synthetic tenant already exists; nothing was changed.');

            return self::SUCCESS;
        }

        $previousTenant = TenantContext::customer();
        try {
            $this->standIn();
            $summary = $this->seed();
        } catch (Throwable $exception) {
            $this->error('Demo seeding stopped: '.$exception::class.'. The synthetic tenant may be partly created; it is safe to leave it, but it must be removed by hand before this command can run again.');

            return self::FAILURE;
        } finally {
            TenantContext::set($previousTenant);
        }

        $this->info('Synthetic tenant "'.self::SLUG.'" created.');
        foreach ($summary as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * The directory of the media disk, only when that disk is the plain `local`
     * driver. Anything else (a remote driver, a missing root) yields null.
     */
    private function mediaRoot(): ?string
    {
        $disk = config('filesystems.disks.'.(string) config('media.disk'));
        $root = is_array($disk) && ($disk['driver'] ?? null) === 'local' ? ($disk['root'] ?? null) : null;

        return is_string($root) && $root !== '' ? rtrim($root, '/\\') : null;
    }

    /**
     * Takes the synthetic tenant's media folder for this command alone. `mkdir`
     * fails if anything (a folder, a file, a link) is already there, and there is
     * no gap between checking and taking, so nothing else's files can be written into.
     */
    private function claimMediaFolder(int $customerId): void
    {
        $tenants = ($this->mediaRoot() ?? throw new \RuntimeException('The media disk is not local.')).'/tenants';
        if (! is_dir($tenants) && ! @mkdir($tenants, 0775, true) && ! is_dir($tenants)) {
            throw new \RuntimeException('The media folder could not be prepared.');
        }
        if (! @mkdir($tenants.'/'.$customerId, 0775)) {
            throw new \RuntimeException('The media folder for the demo tenant already exists.');
        }
    }

    /** Substitutions that live and die with this process. */
    private function standIn(): void
    {
        config([
            'queue.default' => 'sync',
            'media.processing.providers.virus_scan' => 'fake',
            'media.processing.providers.ocr' => 'fake',
            'media.processing.providers.speech_to_text' => 'fake',
            'media.processing.providers.caption' => 'fake',
            'media.processing.providers.structured_extraction' => 'unconfigured',
            'media.processing.versions.virus_scan' => 'fake-v1',
            'media.processing.versions.ocr' => 'fake-v1',
            'media.processing.versions.speech_to_text' => 'fake-v1',
            'media.processing.versions.caption' => 'fake-v1',
            'ai.providers' => [DemoAuthoringProposalProvider::PROVIDER => [
                'managed' => false, 'models' => [DemoAuthoringProposalProvider::MODEL], 'purposes' => ['authoring_proposal'],
                'regions' => ['lf_managed'], 'retention_classes' => ['none', 'transient'], 'data_classes' => ['derived_text'],
            ]],
            'ai.authoring.provider' => DemoAuthoringProposalProvider::PROVIDER,
            'ai.authoring.model' => DemoAuthoringProposalProvider::MODEL,
        ]);

        app()->instance(TenantSettingSource::class, new InMemoryTenantSettings);
        app()->instance(AuthoringProposalProvider::class, new DemoAuthoringProposalProvider);
    }

    /** @return array<int,string> */
    private function seed(): array
    {
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'AI demo', 'slug' => self::SLUG, 'subdomain' => self::SLUG, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $user = fn (string $role, string $name): User => User::forceCreate([
            'customer_id' => $customerId, 'name' => 'AI demo '.$name, 'email' => self::SLUG.'-'.$name.'@example.test',
            'password' => Hash::make(self::PASSWORD), 'role' => $role, 'status' => 'active', 'email_verified_at' => $now,
        ]);
        $admin = $user('customer_admin', 'admin');
        $teacher = $user('teacher', 'teacher');
        TenantContext::set((object) ['id' => $customerId]);

        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'AI demo', 'slug' => self::SLUG,
            'description' => null, 'thumbnail_image' => null, 'banner_image' => null, 'sort_order' => 1,
            'is_featured' => false, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'category_id' => $categoryId, 'title' => 'Toán lớp 4 (dữ liệu mẫu)',
            'short_description' => 'Khoá học mẫu để xem giao diện duyệt đề xuất AI.', 'description' => 'Dữ liệu tổng hợp, không phải nội dung thật.',
            'publisher_name' => 'LearnForge', 'intro_video_source' => null, 'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null, 'difficulty_level' => 'beginner', 'estimated_minutes_per_lesson' => 30,
            'estimated_lesson_count' => null, 'lesson_count' => 1, 'meta_title' => null, 'meta_description' => null,
            'meta_keywords' => null, 'working_revision' => 1, 'status' => 'active', 'created_by' => $admin->id,
            'last_version_published_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_course_template_teachers')->insert([
            'customer_id' => $customerId, 'template_id' => $templateId, 'teacher_id' => $teacher->id, 'role' => 'primary',
            'sort_order' => 0, 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $lessonId = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_section_id' => null, 'title' => 'Bài 1: Phân số',
            'short_description' => null, 'description' => null, 'sort_order' => 0, 'is_preview' => false,
            'duration_seconds' => 0, 'activity_count' => 1, 'unlock_rule' => 'none', 'unlock_after_lesson_id' => null,
            'unlock_at' => null, 'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_lesson_id' => $lessonId,
            'title' => 'Đọc tài liệu về phân số', 'description' => 'Đọc và ghi chú.', 'sort_order' => 0, 'activity_type' => 'document',
            'external_video_url' => null, 'live_class_url' => null, 'assessment_quiz_id' => null, 'duration_seconds' => 600,
            'is_required' => true, 'completion_rule' => 'view', 'completion_threshold' => null, 'is_preview' => false,
            'unlock_rule' => 'none', 'unlock_after_activity_id' => null, 'unlock_at' => null, 'created_by' => $admin->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Media files live under the tenant's own folder of the shared media disk.
        // A folder that already exists there belongs to something else (another
        // database's tenant with the same number, for one), and this command must
        // never write into it: it takes the folder or stops.
        $this->claimMediaFolder($customerId);
        $media = app(MediaService::class)->upload(
            UploadedFile::fake()->createWithContent('phan-so.txt', "Phân số biểu thị một hoặc nhiều phần bằng nhau của một đơn vị.\nTử số cho biết lấy bao nhiêu phần, mẫu số cho biết đơn vị được chia thành bao nhiêu phần.\nĐể so sánh hai phân số cùng mẫu số, so sánh hai tử số."),
            ['file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => $activityId, 'purpose' => 'document'],
            $admin->id,
        );
        DB::table('media_file_usages')->insert([
            'customer_id' => $customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'document', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($customerId, $media->id, 'vi', $admin->id);

        $learning = app(LearningFrameworkAuthoringService::class);
        $framework = $learning->createFramework($admin->id, [
            'code' => 'ai-demo', 'name' => 'Bộ chuẩn Toán (dữ liệu mẫu)', 'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [['key' => 'novice', 'threshold' => 0], ['key' => 'mastered', 'threshold' => 0.8]]],
        ]);
        $definition = $learning->createDefinition($admin->id, [
            'framework_id' => $framework->id, 'code' => 'PHAN-SO', 'node_type' => 'competency', 'canonical_name' => 'Phân số',
        ]);
        $version = $learning->createDraftVersion($admin->id, ['framework_id' => $framework->id, 'version_code' => 'v1', 'title' => 'V1']);
        $learning->createNode($admin->id, ['framework_version_id' => $version->id, 'node_definition_id' => $definition->id]);
        $learning->publishVersion($admin->id, (int) $version->id);
        app(CourseTemplateLearningMappingIntentService::class)->select($admin->id, $customerId, $templateId, (int) $framework->id, (int) $version->id);

        // A real entitlement row for this synthetic tenant only, so the real
        // Commercial ledger reserves and settles the call like any other.
        DB::table('saas_entitlements')->insert([
            'customer_id' => $customerId, 'feature_key' => 'ai_authoring_proposal', 'entitlement_type' => 'integer',
            'entitlement_value' => '1000', 'quota_unit' => 'call', 'quota_period_type' => 'daily',
            'quota_timezone' => 'Asia/Ho_Chi_Minh', 'source_type' => 'plan_feature', 'source_id' => 1,
            'effective_from' => $now->copy()->utc()->subDay(), 'status' => 'active',
        ]);
        app(TenantSettingSource::class)->approve($customerId, 'ai.external_processing.'.DemoAuthoringProposalProvider::PROVIDER.'.authoring_proposal', [
            'approved' => true, 'data_classes' => ['derived_text'], 'execution_regions' => ['lf_managed'], 'retention_classes' => ['none'],
        ]);

        $service = app(AiAuthoringProposalService::class);
        $generated = $service->generate(
            (int) $admin->id, $activityId,
            ['summary', 'concept', 'learning_objective', 'competency', 'node_mapping'],
            (int) $framework->id, (int) $version->id, (string) Str::uuid(),
        );
        if (($generated['error_code'] ?? null) !== null || ($generated['proposals'] ?? []) === []) {
            throw new \RuntimeException('Generation did not complete.');
        }

        // Move a few proposals through real review so several states exist: the
        // first is accepted, the second rejected, the rest stay pending.
        $decisions = ['accept', 'reject'];
        foreach (array_slice($generated['proposals'], 0, count($decisions)) as $index => $proposal) {
            $shown = $service->show((int) $teacher->id, $proposal['proposal_uuid']);
            $service->decide(
                (int) $teacher->id, $proposal['proposal_uuid'], $decisions[$index],
                (int) $shown['revision_no'], (int) $shown['lock_version'], (string) Str::uuid(),
                $decisions[$index] === 'reject' ? 'Dữ liệu mẫu: từ chối.' : null,
            );
        }

        $base = rtrim((string) config('app.url'), '/');
        $host = self::SLUG.'.'.config('app.base_domain').(parse_url($base, PHP_URL_PORT) ? ':'.parse_url($base, PHP_URL_PORT) : '');
        $scheme = (string) config('app.tenant_scheme', 'http');

        return [
            'Proposals created: '.count($generated['proposals']).' (1 accepted, 1 rejected, the rest pending review).',
            'Admin page:   '.$scheme.'://'.$host.'/admin/course-templates/'.$templateId.'/lessons/'.$lessonId.'/activities/'.$activityId,
            'Teacher page: '.$scheme.'://'.$host.'/teacher/course-templates/'.$templateId.'/lessons/'.$lessonId.'/activities/'.$activityId,
            'Accounts: '.self::SLUG.'-admin@example.test and '.self::SLUG.'-teacher@example.test; the password is the constant PASSWORD in this command.',
        ];
    }
}
