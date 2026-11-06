<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Danh mục of the Kiểm tra module (core_assessment_categories, ADR-0025 D3): admin only, tenant scoped,
 * no cycles in the tree, and a category in use is archived instead of deleted.
 */
class AssessmentCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tenant-a.localhost/admin/assessments/categories';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
        ]);

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
    }

    public function test_index_lists_only_this_tenants_categories_with_parent_and_question_count(): void
    {
        $root = $this->category('TOPIK I');
        $child = $this->category('Đọc hiểu', $root);
        $this->question($child);
        $otherTenant = $this->createTenant('tenant-b');
        $this->category('Danh mục tenant khác', null, $otherTenant);

        $html = $this->actingAs($this->admin)->get(self::BASE)->assertOk()
            ->assertSeeText('TOPIK I')
            ->assertSeeText('Đọc hiểu')
            ->assertDontSeeText('Danh mục tenant khác')
            ->assertSeeText(__('lf.table_no'))
            ->assertSee('data-ui-bulk-delete', false)
            ->getContent();

        // The child row shows its parent; both rows are locked against delete (child in use, parent has a child).
        $this->assertMatchesRegularExpression('/Đọc hiểu.*?TOPIK I/s', $html);
        $this->assertSame(2, substr_count($html, 'class="ui-bulk-lock"'));
    }

    public function test_index_filters_by_keyword_and_status(): void
    {
        $this->category('Nghe hiểu');
        $this->category('Viết', null, null, 'archived');

        $this->actingAs($this->admin)->get(self::BASE.'?keyword=nghe')->assertOk()
            ->assertSeeText('Nghe hiểu')->assertDontSeeText('Viết');
        $this->actingAs($this->admin)->get(self::BASE.'?status=archived')->assertOk()
            ->assertSeeText('Viết')->assertDontSeeText('Nghe hiểu');
    }

    public function test_admin_creates_a_category_with_a_unique_slug(): void
    {
        $this->actingAs($this->admin)->postJson(self::BASE, ['name' => 'Đọc hiểu', 'status' => 'active'], self::AJAX)
            ->assertCreated()->assertJson(['message' => __('lf.LF_assessment_category_created')]);
        $this->actingAs($this->admin)->postJson(self::BASE, ['name' => 'Đọc  hiểu', 'status' => 'active'], self::AJAX)
            ->assertCreated();

        $this->assertSame(
            ['doc-hieu', 'doc-hieu-2'],
            DB::table('core_assessment_categories')->where('customer_id', $this->customerId)->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_create_rejects_bad_input(): void
    {
        $foreign = $this->category('Khác', null, $this->createTenant('tenant-b'));

        $this->actingAs($this->admin)->postJson(self::BASE, ['name' => '', 'status' => 'active'], self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        // The CHECK compares case-insensitively, so the app must refuse anything but the lowercase value.
        $this->actingAs($this->admin)->postJson(self::BASE, ['name' => 'A', 'status' => 'Active'], self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($this->admin)->postJson(self::BASE, ['name' => 'A', 'status' => 'active', 'parent_id' => $foreign], self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->assertSame(0, DB::table('core_assessment_categories')->where('customer_id', $this->customerId)->count());
    }

    public function test_update_refuses_a_cycle_in_the_tree(): void
    {
        $root = $this->category('Gốc');
        $child = $this->category('Con', $root);
        $grandchild = $this->category('Cháu', $child);

        foreach ([$root, $child, $grandchild] as $newParent) {
            $this->actingAs($this->admin)
                ->putJson(self::BASE.'/'.$root, ['name' => 'Gốc', 'status' => 'active', 'parent_id' => $newParent], self::AJAX)
                ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }

        $this->assertNull(DB::table('core_assessment_categories')->where('id', $root)->value('parent_id'));
    }

    public function test_update_moves_renames_and_keeps_slug_when_name_is_unchanged(): void
    {
        $a = $this->category('A');
        $b = $this->category('B');
        $slug = DB::table('core_assessment_categories')->where('id', $b)->value('slug');

        $this->actingAs($this->admin)
            ->putJson(self::BASE.'/'.$b, ['name' => 'B', 'status' => 'archived', 'parent_id' => $a, 'description' => 'Ghi chú'], self::AJAX)
            ->assertOk()->assertJson(['message' => __('lf.LF_assessment_category_updated')]);

        $row = DB::table('core_assessment_categories')->find($b);
        $this->assertSame([$a, $slug, 'archived', 'Ghi chú'], [(int) $row->parent_id, $row->slug, $row->status, $row->description]);

        $this->actingAs($this->admin)->putJson(self::BASE.'/'.$b, ['name' => 'Bê', 'status' => 'active'], self::AJAX)->assertOk();
        $this->assertSame('be', DB::table('core_assessment_categories')->where('id', $b)->value('slug'));
    }

    public function test_edit_dialog_offers_only_valid_parents(): void
    {
        $root = $this->category('Gốc');
        $child = $this->category('Con', $root);
        $this->category('Cũ', null, null, 'archived');
        $this->category('Khác');

        $html = $this->actingAs($this->admin)->get(self::BASE.'/'.$root.'/edit', self::AJAX)->assertOk()
            ->assertSee('class="ui-modal__form"', false)
            ->getContent();

        $this->assertStringContainsString('>Khác</option>', $html);
        $this->assertStringNotContainsString('>Con</option>', $html);
        $this->assertStringNotContainsString('>Gốc</option>', $html);
        $this->assertStringNotContainsString('>Cũ</option>', $html);

        $this->actingAs($this->admin)->get(self::BASE.'/create')->assertNotFound();
        $this->actingAs($this->admin)->get(self::BASE.'/'.$child.'/edit', self::AJAX)->assertOk()->assertSee('>Gốc</option>', false);
    }

    public function test_toggle_status_switches_between_active_and_archived(): void
    {
        $id = $this->category('A');

        $this->actingAs($this->admin)->post(self::BASE.'/'.$id.'/toggle-status')->assertRedirect();
        $this->assertSame('archived', DB::table('core_assessment_categories')->where('id', $id)->value('status'));

        $this->actingAs($this->admin)->post(self::BASE.'/'.$id.'/toggle-status')->assertRedirect();
        $this->assertSame('active', DB::table('core_assessment_categories')->where('id', $id)->value('status'));
    }

    public function test_bulk_delete_is_all_or_nothing_and_blocks_categories_in_use(): void
    {
        $used = $this->category('Đang dùng');
        $this->question($used);
        $free = $this->category('Trống');

        $this->actingAs($this->admin)->deleteJson(self::BASE, ['ids' => [$free, $used]], self::AJAX)
            ->assertStatus(409)->assertJsonFragment(['message' => '«Đang dùng» — '.trans_choice('lf.LF_assessment_category_delete_blocked_questions', 1, ['count' => 1])]);
        $this->assertSame(2, DB::table('core_assessment_categories')->whereIn('id', [$free, $used])->count());

        $this->actingAs($this->admin)->deleteJson(self::BASE, ['ids' => [$free]], self::AJAX)->assertOk();
        $this->assertFalse(DB::table('core_assessment_categories')->where('id', $free)->exists());
    }

    public function test_a_parent_can_be_deleted_together_with_its_children_but_not_alone(): void
    {
        $root = $this->category('Gốc');
        $child = $this->category('Con', $root);
        $grandchild = $this->category('Cháu', $child);

        $this->actingAs($this->admin)->deleteJson(self::BASE, ['ids' => [$root]], self::AJAX)->assertStatus(409);
        $this->actingAs($this->admin)->deleteJson(self::BASE, ['ids' => [$root, $child, $grandchild]], self::AJAX)->assertOk();

        $this->assertSame(0, DB::table('core_assessment_categories')->where('customer_id', $this->customerId)->count());
    }

    public function test_another_tenants_category_cannot_be_touched(): void
    {
        $foreign = $this->category('Khác', null, $this->createTenant('tenant-b'));

        $this->actingAs($this->admin)->get(self::BASE.'/'.$foreign.'/edit', self::AJAX)->assertNotFound();
        $this->actingAs($this->admin)->putJson(self::BASE.'/'.$foreign, ['name' => 'X', 'status' => 'active'], self::AJAX)->assertNotFound();
        $this->actingAs($this->admin)->post(self::BASE.'/'.$foreign.'/toggle-status')->assertNotFound();
        $this->actingAs($this->admin)->deleteJson(self::BASE, ['ids' => [$foreign]], self::AJAX)->assertNotFound();

        $this->assertSame('Khác', DB::table('core_assessment_categories')->where('id', $foreign)->value('name'));
    }

    public function test_teachers_and_students_cannot_manage_categories(): void
    {
        $id = $this->category('A');
        $teacher = $this->createUser($this->customerId, 'teacher');
        $student = $this->createUser($this->customerId, 'student');

        foreach ([$teacher, $student] as $user) {
            $this->actingAs($user)->get(self::BASE)->assertForbidden();
            $this->actingAs($user)->postJson(self::BASE, ['name' => 'X', 'status' => 'active'], self::AJAX)->assertForbidden();
            $this->actingAs($user)->deleteJson(self::BASE, ['ids' => [$id]], self::AJAX)->assertForbidden();
        }

        $this->actingAs($teacher)->get('https://tenant-a.localhost/teacher/assessments/categories')->assertNotFound();
        $this->actingAs($teacher)->post('https://tenant-a.localhost/teacher/assessments/categories', ['name' => 'X'])->assertNotFound();
        $this->assertSame(1, DB::table('core_assessment_categories')->where('customer_id', $this->customerId)->count());
    }

    private function category(string $name, ?int $parentId = null, ?int $customerId = null, string $status = 'active'): int
    {
        static $sequence = 0;
        $sequence++;

        return DB::table('core_assessment_categories')->insertGetId([
            'customer_id' => $customerId ?? $this->customerId,
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => 'cat-'.$sequence,
            'sort_order' => $sequence,
            'status' => $status,
            'created_at' => now()->addSeconds($sequence),
            'updated_at' => now(),
        ]);
    }

    private function question(int $categoryId): void
    {
        DB::table('core_assessment_questions')->insert([
            'customer_id' => $this->customerId,
            'category_id' => $categoryId,
            'created_by' => $this->admin->id,
            'question_type' => 'single_choice',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTenant(string $slug): int
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
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
