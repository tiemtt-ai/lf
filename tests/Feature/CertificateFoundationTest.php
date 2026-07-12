<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CertificateFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
        ]);
    }

    public function test_certificate_foundation_tables_exist_with_customer_id(): void
    {
        foreach ([
            'core_certificate_templates',
            'core_certificate_template_products',
            'core_certificate_issued_certificates',
            'core_certificate_verification_logs',
            'core_certificate_download_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertTrue(Schema::hasColumn($table, 'customer_id'));
        }
    }

    public function test_certificate_version_fields_use_version_id_not_template_version_id(): void
    {
        foreach ([
            'core_certificate_template_products',
            'core_certificate_issued_certificates',
        ] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'version_id'));
            $this->assertFalse(Schema::hasColumn($table, 'template_version_id'));
        }
    }

    public function test_certificate_tables_do_not_add_forbidden_completion_or_media_binary_fields(): void
    {
        foreach ([
            'certificate_eligible',
            'certificate_issued',
            'certificate_issued_at',
        ] as $field) {
            $this->assertFalse(Schema::hasColumn('core_course_completions', $field));
        }

        foreach ([
            'core_certificate_templates',
            'core_certificate_issued_certificates',
        ] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'media_binary'));
            $this->assertFalse(Schema::hasColumn($table, 'file_binary'));
            $this->assertFalse(Schema::hasColumn($table, 'pdf_binary'));
        }
    }

    public function test_certificate_template_code_is_unique_per_tenant_only(): void
    {
        $firstCustomerId = $this->createTenant('tenant-a');
        $secondCustomerId = $this->createTenant('tenant-b');

        $this->createCertificateTemplate($firstCustomerId, 'CERT-TOPIK');
        $this->createCertificateTemplate($secondCustomerId, 'CERT-TOPIK');

        $this->assertSame(2, DB::table('core_certificate_templates')->where('template_code', 'CERT-TOPIK')->count());

        $this->expectException(QueryException::class);

        $this->createCertificateTemplate($firstCustomerId, 'CERT-TOPIK');
    }

    public function test_certificate_mapping_references_product_and_version_and_rejects_invalid_fks(): void
    {
        $context = $this->certificateContext();

        $mappingId = $this->createCertificateMapping(
            $context['customer_id'],
            $context['certificate_template_id'],
            $context['product_id'],
            $context['version_id']
        );

        $this->assertDatabaseHas('core_certificate_template_products', [
            'id' => $mappingId,
            'customer_id' => $context['customer_id'],
            'certificate_template_id' => $context['certificate_template_id'],
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        $this->insertCertificateMapping([
            'customer_id' => $context['customer_id'],
            'certificate_template_id' => $context['certificate_template_id'],
            'product_id' => $context['product_id'],
            'version_id' => 999999,
        ]);
    }

    public function test_duplicate_certificate_template_product_version_mapping_is_rejected(): void
    {
        $context = $this->certificateContext();

        $this->createCertificateMapping(
            $context['customer_id'],
            $context['certificate_template_id'],
            $context['product_id'],
            $context['version_id']
        );

        $this->expectException(QueryException::class);

        $this->createCertificateMapping(
            $context['customer_id'],
            $context['certificate_template_id'],
            $context['product_id'],
            $context['version_id']
        );
    }

    public function test_issued_certificate_references_enrollment_and_snapshots_certificate_context(): void
    {
        $context = $this->issuedCertificateContext();

        $certificateId = $this->createIssuedCertificateFromCompletion($context);

        $this->assertDatabaseHas('core_certificate_issued_certificates', [
            'id' => $certificateId,
            'customer_id' => $context['customer_id'],
            'certificate_template_id' => $context['certificate_template_id'],
            'certificate_template_product_id' => $context['certificate_template_product_id'],
            'completion_id' => $context['completion_id'],
            'enrollment_id' => $context['enrollment_id'],
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
            'student_id' => $context['student']->id,
            'recipient_name' => $context['student']->name,
            'status' => 'issued',
        ]);
    }

    public function test_re_enrollment_can_have_separate_issued_certificates(): void
    {
        $context = $this->learningContext();
        $certificateTemplateId = $this->createCertificateTemplate($context['customer_id']);
        $mappingId = $this->createCertificateMapping(
            $context['customer_id'],
            $certificateTemplateId,
            $context['product_id'],
            $context['version_id']
        );

        $first = $this->completionContext($context);
        $second = $this->completionContext($context);

        $this->createIssuedCertificateFromCompletion(array_merge($first, [
            'certificate_template_id' => $certificateTemplateId,
            'certificate_template_product_id' => $mappingId,
            'certificate_number' => 'CERT-ONE',
            'verification_code' => 'VERIFY-ONE',
        ]));
        $this->createIssuedCertificateFromCompletion(array_merge($second, [
            'certificate_template_id' => $certificateTemplateId,
            'certificate_template_product_id' => $mappingId,
            'certificate_number' => 'CERT-TWO',
            'verification_code' => 'VERIFY-TWO',
        ]));

        $this->assertSame(
            2,
            DB::table('core_certificate_issued_certificates')
                ->where('customer_id', $context['customer_id'])
                ->where('student_id', $context['student']->id)
                ->where('product_id', $context['product_id'])
                ->count()
        );
    }

    public function test_one_certificate_per_completion_is_enforced(): void
    {
        $context = $this->issuedCertificateContext();

        $this->createIssuedCertificateFromCompletion($context);

        $this->expectException(QueryException::class);

        $this->createIssuedCertificateFromCompletion(array_merge($context, [
            'certificate_number' => 'CERT-DUPLICATE',
            'verification_code' => 'VERIFY-DUPLICATE',
        ]));
    }

    public function test_issued_certificate_does_not_mutate_course_completion(): void
    {
        $context = $this->issuedCertificateContext();
        $before = (array) DB::table('core_course_completions')->where('id', $context['completion_id'])->first();

        $this->createIssuedCertificateFromCompletion($context);

        $after = (array) DB::table('core_course_completions')->where('id', $context['completion_id'])->first();

        $this->assertSame($before, $after);
    }

    public function test_verification_and_download_logs_are_append_only_audit_rows(): void
    {
        $context = $this->issuedCertificateContext();
        $certificateId = $this->createIssuedCertificateFromCompletion($context);

        $this->createVerificationLog($context['customer_id'], $certificateId, 'success');
        $this->createVerificationLog($context['customer_id'], null, 'not_found');
        $this->createDownloadLog($context['customer_id'], $certificateId, 'view');
        $this->createDownloadLog($context['customer_id'], $certificateId, 'download_pdf');

        $this->assertSame(2, DB::table('core_certificate_verification_logs')->where('customer_id', $context['customer_id'])->count());
        $this->assertSame(2, DB::table('core_certificate_download_logs')->where('customer_id', $context['customer_id'])->count());
        $this->assertDatabaseHas('core_certificate_verification_logs', [
            'customer_id' => $context['customer_id'],
            'certificate_id' => null,
            'result' => 'not_found',
        ]);
    }

    public function test_certificate_foundation_has_no_manual_crud_routes_or_eloquent_models(): void
    {
        foreach ([
            'certificate-templates',
            'certificate-template-products',
            'certificate-issued-certificates',
            'certificate-verification-logs',
            'certificate-download-logs',
        ] as $resource) {
            foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy', 'archive'] as $action) {
                $this->assertFalse(Route::has("admin.{$resource}.{$action}"));
                $this->assertFalse(Route::has("teacher.{$resource}.{$action}"));
                $this->assertFalse(Route::has("student.{$resource}.{$action}"));
            }
        }

        foreach ([
            'CertificateTemplate',
            'CertificateTemplateProduct',
            'CertificateIssuedCertificate',
            'CertificateVerificationLog',
            'CertificateDownloadLog',
        ] as $model) {
            $this->assertFileDoesNotExist(app_path("Models/{$model}.php"));
            $this->assertFileDoesNotExist(app_path("Models/Core{$model}.php"));
        }
    }

    public function test_no_out_of_scope_certificate_behaviors_or_domains_are_added(): void
    {
        $this->assertFalse(Schema::hasTable('track_events'));
        $this->assertFalse(Schema::hasTable('ai_recommendations'));
        $this->assertFalse(Schema::hasTable('billing_invoices'));
        $this->assertFalse(Schema::hasTable('core_assessment_results'));

        foreach ([
            'certificate_pdf_jobs',
            'certificate_render_jobs',
            'certificate_verification_pages',
            'certificate_download_endpoints',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    private function issuedCertificateContext(): array
    {
        $context = $this->learningContext();
        $certificateTemplateId = $this->createCertificateTemplate($context['customer_id']);
        $mappingId = $this->createCertificateMapping(
            $context['customer_id'],
            $certificateTemplateId,
            $context['product_id'],
            $context['version_id']
        );
        $completionContext = $this->completionContext($context);

        return array_merge($completionContext, [
            'certificate_template_id' => $certificateTemplateId,
            'certificate_template_product_id' => $mappingId,
            'certificate_number' => 'CERT-'.uniqid(),
            'verification_code' => 'VERIFY-'.uniqid(),
        ]);
    }

    private function completionContext(array $context): array
    {
        $enrollmentId = $this->createEnrollment(
            $context['customer_id'],
            $context['student']->id,
            $context['product_id'],
            $context['version_id']
        );
        $courseProgressId = $this->createProgressFromEnrollment($context['customer_id'], $enrollmentId);
        $completionId = $this->createCompletionFromCourseProgress($context['customer_id'], $courseProgressId);

        return array_merge($context, [
            'enrollment_id' => $enrollmentId,
            'course_progress_id' => $courseProgressId,
            'completion_id' => $completionId,
        ]);
    }

    private function certificateContext(): array
    {
        $context = $this->learningContext();

        return array_merge($context, [
            'certificate_template_id' => $this->createCertificateTemplate($context['customer_id']),
        ]);
    }

    private function learningContext(): array
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id, 'TOPIK Beginner');

        return [
            'customer_id' => $customerId,
            'admin' => $admin,
            'student' => $student,
            'product_id' => $productId,
            'version_id' => $versionId,
        ];
    }

    private function createIssuedCertificateFromCompletion(array $context): int
    {
        $student = $context['student'];
        $now = now();

        return DB::table('core_certificate_issued_certificates')->insertGetId([
            'customer_id' => $context['customer_id'],
            'certificate_template_id' => $context['certificate_template_id'],
            'certificate_template_product_id' => $context['certificate_template_product_id'],
            'completion_id' => $context['completion_id'],
            'enrollment_id' => $context['enrollment_id'],
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
            'student_id' => $student->id,
            'issued_by' => null,
            'certificate_number' => $context['certificate_number'] ?? 'CERT-'.uniqid(),
            'verification_code' => $context['verification_code'] ?? 'VERIFY-'.uniqid(),
            'verification_url' => null,
            'recipient_name' => $student->name,
            'recipient_email' => $student->email,
            'product_title_snapshot' => 'TOPIK Beginner',
            'product_code_snapshot' => 'TOPIK-BEGINNER',
            'course_template_title_snapshot' => 'TOPIK Beginner',
            'template_code_snapshot' => 'CERT-TOPIK',
            'template_name_snapshot' => 'TOPIK Completion Certificate',
            'certificate_template_version_snapshot' => 1,
            'course_template_version_number_snapshot' => 1,
            'render_engine_snapshot' => 'html_pdf',
            'layout_data_snapshot' => null,
            'completion_rule_snapshot' => 'all_required_activities',
            'final_score' => null,
            'max_score' => null,
            'passed' => null,
            'completed_at' => $now,
            'issued_at' => $now,
            'expires_at' => null,
            'issue_source' => 'system',
            'issue_note' => null,
            'file_id' => null,
            'file_url' => null,
            'qr_code_data' => null,
            'status' => 'issued',
            'revoked_at' => null,
            'revoked_by' => null,
            'revoked_reason' => null,
            'reissued_from_id' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createCertificateMapping(
        int $customerId,
        int $certificateTemplateId,
        int $productId,
        int $versionId
    ): int {
        return $this->insertCertificateMapping([
            'customer_id' => $customerId,
            'certificate_template_id' => $certificateTemplateId,
            'product_id' => $productId,
            'version_id' => $versionId,
        ]);
    }

    private function insertCertificateMapping(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_certificate_template_products')->insertGetId(array_merge([
            'customer_id' => 1,
            'certificate_template_id' => 1,
            'product_id' => 1,
            'version_id' => 1,
            'completion_required_percentage' => 100,
            'minimum_score_percentage' => null,
            'issue_mode' => 'automatic',
            'validity_days' => null,
            'is_active' => true,
            'status' => 'active',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function createCertificateTemplate(int $customerId, string $code = 'CERT-TOPIK'): int
    {
        $now = now();

        return DB::table('core_certificate_templates')->insertGetId([
            'customer_id' => $customerId,
            'template_code' => $code,
            'name' => 'TOPIK Completion Certificate',
            'description' => null,
            'template_version' => 1,
            'language' => 'en',
            'background_file_id' => null,
            'logo_file_id' => null,
            'signature_file_id' => null,
            'seal_file_id' => null,
            'title' => 'Certificate of Completion',
            'subtitle' => null,
            'content_template' => 'This certifies that {{student_name}} completed {{product_name}}.',
            'certificate_number_prefix' => 'TOPIK',
            'default_validity_days' => null,
            'render_engine' => 'html_pdf',
            'layout_data' => null,
            'qr_code_enabled' => true,
            'verification_enabled' => true,
            'is_default' => false,
            'sort_order' => 1,
            'status' => 'active',
            'metadata' => null,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createVerificationLog(int $customerId, ?int $certificateId, string $result): int
    {
        $now = now();

        return DB::table('core_certificate_verification_logs')->insertGetId([
            'customer_id' => $customerId,
            'certificate_id' => $certificateId,
            'user_id' => null,
            'verification_code' => 'VERIFY-'.uniqid(),
            'verification_url' => null,
            'result' => $result,
            'certificate_status_snapshot' => $certificateId ? 'issued' : null,
            'recipient_name_snapshot' => null,
            'product_title_snapshot' => null,
            'certificate_number_snapshot' => null,
            'verified_at' => $now,
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
            'referer' => null,
            'country' => null,
            'city' => null,
            'metadata' => null,
            'created_at' => $now,
        ]);
    }

    private function createDownloadLog(int $customerId, int $certificateId, string $action): int
    {
        $now = now();

        return DB::table('core_certificate_download_logs')->insertGetId([
            'customer_id' => $customerId,
            'certificate_id' => $certificateId,
            'user_id' => null,
            'action' => $action,
            'source' => 'web',
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
            'referer_url' => null,
            'country' => null,
            'city' => null,
            'activity_at' => $now,
            'metadata' => null,
            'created_at' => $now,
        ]);
    }

    private function createCompletionFromCourseProgress(int $customerId, int $courseProgressId): int
    {
        $courseProgress = DB::table('core_course_progress')
            ->where('customer_id', $customerId)
            ->where('id', $courseProgressId)
            ->first();

        return $this->insertCompletion([
            'customer_id' => $customerId,
            'enrollment_id' => $courseProgress->enrollment_id,
            'course_progress_id' => $courseProgress->id,
            'student_id' => $courseProgress->student_id,
            'product_id' => $courseProgress->product_id,
            'version_id' => $courseProgress->version_id,
        ]);
    }

    private function createProgressFromEnrollment(int $customerId, int $enrollmentId): int
    {
        $enrollment = DB::table('core_course_enrollments')
            ->where('customer_id', $customerId)
            ->where('id', $enrollmentId)
            ->first();

        return $this->insertProgress([
            'customer_id' => $customerId,
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'product_id' => $enrollment->product_id,
            'version_id' => $enrollment->version_id,
        ]);
    }

    private function insertCompletion(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_completions')->insertGetId(array_merge([
            'customer_id' => 1,
            'enrollment_id' => 1,
            'course_progress_id' => 1,
            'student_id' => 1,
            'product_id' => 1,
            'version_id' => 1,
            'completion_rule' => 'all_required_activities',
            'required_progress_percentage' => 100,
            'final_progress_percentage' => 100,
            'completed_lessons' => 0,
            'total_lessons' => 0,
            'completed_activities' => 0,
            'total_activities' => 0,
            'required_activities_completed' => 0,
            'required_activities_total' => 0,
            'assessment_completed' => 0,
            'assessment_total' => 0,
            'final_score' => null,
            'max_score' => null,
            'passed' => null,
            'completed_at' => $now,
            'completed_by' => null,
            'completion_source' => 'system',
            'status' => 'completed',
            'revoked_at' => null,
            'revoked_by' => null,
            'revoked_reason' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function insertProgress(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_progress')->insertGetId(array_merge([
            'customer_id' => 1,
            'enrollment_id' => 1,
            'student_id' => 1,
            'product_id' => 1,
            'version_id' => 1,
            'progress_percentage' => 0,
            'completed_lessons' => 0,
            'total_lessons' => 0,
            'completed_activities' => 0,
            'total_activities' => 0,
            'required_activities_completed' => 0,
            'required_activities_total' => 0,
            'assessment_completed' => 0,
            'assessment_total' => 0,
            'total_learning_seconds' => 0,
            'last_version_activity_id' => null,
            'last_version_lesson_id' => null,
            'last_accessed_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'status' => 'not_started',
            'recalculated_at' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function createTenant(string $slug = 'tenant-a'): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'subdomain' => $slug,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createProduct(int $customerId, string $title, string $slug): int
    {
        $now = now();

        return DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId,
            'product_code' => strtoupper($slug).'-'.uniqid(),
            'product_type' => 'single_course',
            'title' => $title,
            'slug' => $slug,
            'short_description' => null,
            'description' => null,
            'thumbnail_type' => 'image',
            'thumbnail_image' => null,
            'thumbnail_video_source' => null,
            'thumbnail_video_url' => null,
            'thumbnail_video_media_id' => null,
            'price' => 0,
            'sale_price' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
            'currency' => 'VND',
            'enrollment_type' => 'paid',
            'max_students' => null,
            'enrollment_count' => 0,
            'access_duration_days' => null,
            'review_duration_days' => null,
            'is_certificate_enabled' => false,
            'is_refundable' => false,
            'refund_days' => null,
            'tags' => null,
            'badge_type' => null,
            'show_enrollment_count' => true,
            'display_enrollment_count' => null,
            'is_featured' => false,
            'sort_order' => 0,
            'visibility' => 'public',
            'available_from' => null,
            'available_until' => null,
            'registration_starts_at' => null,
            'registration_ends_at' => null,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'active',
            'created_by' => null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createTemplate(int $customerId, int $userId, string $title): int
    {
        $now = now();

        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'title' => $title,
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'intro_video_source' => null,
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
            'difficulty_level' => null,
            'estimated_minutes_per_lesson' => 0,
            'estimated_lesson_count' => null,
            'lesson_count' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'working_revision' => 1,
            'status' => 'active',
            'created_by' => $userId,
            'last_version_published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createVersion(int $customerId, int $userId, string $title): int
    {
        $now = now();
        $templateId = $this->createTemplate($customerId, $userId, $title);

        return DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'version_number' => 1,
            'version_code' => 'VERSION-'.$templateId.'-1',
            'is_current' => true,
            'source_category_id' => null,
            'category_name_snapshot' => null,
            'title_snapshot' => $title,
            'short_description_snapshot' => null,
            'description_snapshot' => null,
            'publisher_name_snapshot' => null,
            'intro_video_source_snapshot' => null,
            'intro_image_media_file_id_snapshot' => null,
            'intro_video_media_file_id_snapshot' => null,
            'difficulty_level_snapshot' => null,
            'estimated_minutes_per_lesson_snapshot' => 0,
            'estimated_lesson_count_snapshot' => null,
            'lesson_count_snapshot' => 0,
            'meta_title_snapshot' => null,
            'meta_description_snapshot' => null,
            'meta_keywords_snapshot' => null,
            'source_working_revision' => 1,
            'status' => 'published',
            'published_at' => $now,
            'published_by' => $userId,
            'source_template_updated_at' => $now,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createEnrollment(
        int $customerId,
        int $studentId,
        int $productId,
        int $versionId
    ): int {
        $now = now();

        return DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $customerId,
            'student_id' => $studentId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'source' => 'admin',
            'source_id' => null,
            'enrolled_by' => null,
            'enrolled_at' => $now,
            'access_starts_at' => null,
            'access_ends_at' => null,
            'review_starts_at' => null,
            'review_ends_at' => null,
            'status' => 'active',
            'completed_at' => null,
            'cancelled_at' => null,
            'expired_at' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
