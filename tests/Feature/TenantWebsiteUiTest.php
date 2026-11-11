<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantWebsiteUiTest extends TestCase
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

    public function test_guest_sees_the_tenant_storefront_and_public_course_actions(): void
    {
        $customerId = $this->createTenant();
        $this->createCatalogCourse($customerId, 'Tiếng Hàn sơ cấp');

        $this->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertSeeText(__('lf.LF_navigation_menu_public_home'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_courses'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_assessments'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_services'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_teachers'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_about'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_contact'))
            ->assertSeeText(__('lf.LF_navigation_menu_guest_login'))
            ->assertSeeText(__('lf.LF_home_public_featured_courses'))
            ->assertSeeText(__('lf.LF_home_public_featured_services'))
            ->assertSeeText(__('lf.LF_home_public_news'))
            ->assertSeeText(__('lf.LF_home_public_contact_cta'))
            ->assertDontSeeText(__('lf.LF_navigation_menu_student_learning_history'))
            ->assertSeeText(__('lf.LF_course_card_guest_login_register_purchase'));

        foreach (['/courses', '/assessments', '/services', '/teachers', '/about', '/contact'] as $path) {
            $this->get('https://tenant-a.localhost'.$path)->assertOk();
        }
    }

    public function test_verified_student_sees_public_and_personalized_content_on_the_same_homepage(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertSeeText(__('lf.LF_home_public_featured_courses'))
            ->assertSeeText(__('lf.LF_home_public_featured_services'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_teachers'))
            ->assertSeeText(__('lf.LF_home_public_news'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_my_courses'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_learning_history'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_ai_tutor'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_profile'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_logout'))
            ->assertSeeText(__('lf.LF_home_student_continue_learning'))
            ->assertSeeText(__('lf.LF_home_student_upcoming_activities'))
            ->assertSeeText(__('lf.LF_home_student_pending_assessments'))
            ->assertSeeText(__('lf.LF_home_student_ai_recommendations'))
            ->assertSee('href="https://tenant-a.localhost/profile"', false)
            ->assertSee('action="https://tenant-a.localhost/logout"', false);
    }

    public function test_student_personalized_routes_are_role_protected(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');

        $paths = ['/my-courses', '/learning-history', '/ai-tutor', '/profile'];

        foreach ($paths as $path) {
            $this->get('https://tenant-a.localhost'.$path)
                ->assertRedirect('https://tenant-a.localhost/login?experience=student');
        }

        foreach ($paths as $path) {
            $this->actingAs($teacher)
                ->get('https://tenant-a.localhost'.$path)
                ->assertForbidden();
        }

        foreach ($paths as $path) {
            $this->actingAs($student)
                ->get('https://tenant-a.localhost'.$path)
                ->assertOk();
        }

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/admin')
            ->assertForbidden();

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/teacher')
            ->assertForbidden();

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/admin')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/teacher')
            ->assertForbidden();
    }

    public function test_the_catalogue_lists_visible_published_products_of_the_tenant_only(): void
    {
        $customerId = $this->createTenant();
        $otherTenant = DB::table('saas_customers')->insertGetId([
            'name' => 'Tenant B', 'slug' => 'tenant-b', 'subdomain' => 'tenant-b', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $open = $this->createCatalogCourse($customerId, 'Khoá đang mở', ['price' => 1000000, 'promotion_enabled' => true,
            'discount_type' => 'percentage', 'discount_value' => 20]);
        $this->createCatalogCourse($customerId, 'Khoá ẩn', ['visibility' => 'hidden']);
        $this->createCatalogCourse($customerId, 'Khoá riêng', ['visibility' => 'private']);
        $this->createCatalogCourse($customerId, 'Khoá ngừng', ['status' => 'inactive']);
        $this->createCatalogCourse($customerId, 'Khoá chưa hiện', ['available_from' => now()->addDay()]);
        $this->createCatalogCourse($customerId, 'Khoá hết hiện', ['available_until' => now()->subDay()]);
        $this->createCatalogCourse($customerId, 'Khoá phiên bản cũ', [], 'deprecated');
        $this->createCatalogCourse($otherTenant, 'Khoá tenant khác');

        $html = $this->get('https://tenant-a.localhost/courses')->assertOk()
            ->assertSeeText('Khoá đang mở')->assertSeeText('800.000 VND')->assertSee('<s>1.000.000 VND</s>', false)
            ->assertSeeText(__('lf.LF_course_card_guest_login_register_purchase'))
            ->assertDontSeeText('Practical Korean Communication')->getContent();
        foreach (['Khoá ẩn', 'Khoá riêng', 'Khoá ngừng', 'Khoá chưa hiện', 'Khoá hết hiện', 'Khoá phiên bản cũ', 'Khoá tenant khác'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }

        $this->get('https://tenant-a.localhost/courses/'.$open['slug'])->assertOk()
            ->assertSeeText('Khoá đang mở')->assertSeeText('Bài 1: Làm quen')->assertSeeText(__('lf.LF_course_card_public_register_soon'));
        $this->get('https://tenant-a.localhost/courses/khoa-an')->assertNotFound();
        $this->get('https://tenant-a.localhost/courses/khoa-tenant-khac')->assertNotFound();
    }

    public function test_course_actions_distinguish_guest_available_and_enrolled_states(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');
        $enrolled = $this->createCatalogCourse($customerId, 'Khoá đã ghi danh');
        $available = $this->createCatalogCourse($customerId, 'Khoá chưa ghi danh');
        $closed = $this->createCatalogCourse($customerId, 'Khoá hết đăng ký', [
            'registration_starts_at' => now()->subDays(10), 'registration_ends_at' => now()->subDay(),
        ]);
        $enrollment = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $customerId, 'product_id' => $enrolled['product'], 'version_id' => $enrolled['version'],
            'student_id' => $student->id, 'source' => 'admin', 'enrolled_at' => now(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('https://tenant-a.localhost/courses')->assertOk()
            ->assertSeeText(__('lf.LF_course_card_guest_login_register_purchase'))
            ->assertDontSeeText(__('lf.LF_course_card_student_enter'));

        $html = $this->actingAs($student)->get('https://tenant-a.localhost/courses')->assertOk()
            ->assertSee('href="https://tenant-a.localhost/my-courses/'.$enrollment.'"', false)
            ->assertSeeText(__('lf.LF_course_card_student_enter'))
            ->assertSeeText(__('lf.LF_course_card_student_enrolled'))
            ->assertDontSeeText(__('lf.LF_course_card_student_continue_learning'))
            ->getContent();
        $this->assertSame(1, substr_count($html, __('lf.LF_course_card_student_enter')));
        $this->assertMatchesRegularExpression('#data-catalog-course="'.$available['product'].'".*?data-coming-soon aria-disabled="true">'
            .preg_quote(__('lf.LF_course_card_student_register'), '#').'#s', $html);
        $this->assertDoesNotMatchRegularExpression('#data-catalog-course="'.$closed['product'].'"((?!</article>).)*data-coming-soon#s', $html);

        $this->actingAs($student)->get('https://tenant-a.localhost/courses/'.$closed['slug'])->assertOk()
            ->assertSeeText(__('lf.LF_course_card_public_registration_closed'));
    }

    /** @return array{product: int, version: int, slug: string} */
    private function createCatalogCourse(int $customerId, string $title, array $product = [], string $versionStatus = 'published'): array
    {
        $admin = $this->createUser($customerId, 'customer_admin');
        $slug = Str::slug($title);
        $productId = DB::table('core_course_products')->insertGetId($product + [
            'customer_id' => $customerId, 'product_code' => 'P-'.uniqid(), 'product_type' => 'single_course',
            'title' => $title, 'slug' => $slug, 'thumbnail_type' => 'image', 'price' => 0, 'currency' => 'VND',
            'enrollment_type' => 'paid', 'enrollment_count' => 0, 'is_certificate_enabled' => false, 'is_refundable' => false,
            'show_enrollment_count' => true, 'is_featured' => false, 'sort_order' => 0, 'visibility' => 'public',
            'status' => 'active', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'course_type' => 'self_paced', 'customer_id' => $customerId, 'title' => $title,
            'estimated_minutes_per_lesson' => 0, 'lesson_count' => 0, 'working_revision' => 1,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = DB::table('core_course_template_versions')->insertGetId([
            'course_type_snapshot' => 'self_paced', 'customer_id' => $customerId, 'template_id' => $templateId,
            'version_number' => 1, 'version_code' => 'V-'.$templateId, 'is_current' => true,
            'title_snapshot' => $title, 'short_description_snapshot' => 'Mô tả '.$title,
            'estimated_minutes_per_lesson_snapshot' => 0, 'lesson_count_snapshot' => 1,
            'source_working_revision' => 1, 'status' => $versionStatus, 'published_at' => now(), 'published_by' => $admin->id,
            'source_template_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_template_version_lessons')->insert([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'source_template_lesson_id' => $templateId * 10,
            'title_snapshot' => 'Bài 1: Làm quen', 'sort_order' => 1, 'unlock_rule_snapshot' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_product_items')->insert([
            'customer_id' => $customerId, 'product_id' => $productId, 'version_id' => $versionId, 'template_id' => $templateId,
            'title_override' => null, 'short_description_override' => null, 'sort_order' => 0, 'is_required' => true,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['product' => $productId, 'version' => $versionId, 'slug' => $slug];
    }

    private function createTenant(): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'subdomain' => 'tenant-a',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@tenant-website.test',
            'password' => Hash::make('test-only-password'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
