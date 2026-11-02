<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LearningFrameworkIndexTest extends TestCase
{
    use RefreshDatabase;

    private const LIST_URL = 'https://framework-search.localhost/admin/learning-frameworks';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);
        TenantContext::set(null);

        // Read-only SQLite fixture: does not emulate the MariaDB write/trigger contract.
        Schema::create('core_learning_frameworks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            foreach (['code', 'name', 'description', 'default_mastery_scale_key', 'default_mastery_scale_version', 'default_mastery_scale', 'status'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['archived_by', 'created_by', 'updated_by'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('core_learning_framework_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('framework_id');
            $table->unsignedInteger('version_number');
            $table->string('status');
        });
        Schema::create('core_learning_node_definitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('framework_id');
        });
        Schema::create('core_learning_nodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('framework_version_id');
            $table->string('status');
        });

        $tenant = $this->tenant('framework-search');
        $this->admin = User::forceCreate([
            'customer_id' => $tenant, 'name' => 'Admin', 'email' => 'admin@search.test',
            'password' => bcrypt('password'), 'role' => 'customer_admin', 'status' => 'active',
            'email_verified_at' => now(),
        ]);
        DB::table('core_learning_frameworks')->insert([
            ['customer_id' => $tenant, 'name' => 'Chuẩn tiếng Hàn', 'code' => 'KOR-01', 'description' => 'Lộ trình TOPIK', 'status' => 'active'],
            ['customer_id' => $tenant, 'name' => 'Năng lực số', 'code' => 'DIG-01', 'description' => 'Kỹ năng máy tính', 'status' => 'archived'],
            ['customer_id' => $this->tenant('framework-other'), 'name' => 'Chuẩn tiếng Hàn private', 'code' => 'KOR-PRIVATE', 'description' => 'TOPIK', 'status' => 'active'],
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    public static function keywords(): array
    {
        return [['tieng han'], ['TIẾNG HÀN'], [' KOR-01 '], ['topik']];
    }

    #[DataProvider('keywords')]
    public function test_search_matches_name_code_and_description_with_tenant_isolation(string $keyword): void
    {
        $this->actingAs($this->admin)->get($this->url($keyword))
            ->assertOk()->assertViewHas('totalCount', 2)
            ->assertViewHas('frameworks', fn ($items) => $items->count() === 1 && $items->first()->code === 'KOR-01')
            ->assertSeeText('Chuẩn tiếng Hàn')->assertDontSeeText('Năng lực số')
            ->assertDontSeeText('private');
    }

    public function test_blank_search_retains_the_original_list_including_archived_frameworks(): void
    {
        $this->actingAs($this->admin)->get($this->url('   '))
            ->assertOk()->assertViewHas('keyword', '')
            ->assertViewHas('frameworks', fn ($items) => $items->count() === 2)
            ->assertSeeText('Năng lực số')->assertDontSeeText('private');
    }

    public function test_no_results_offer_clear_search_and_preserve_create_action(): void
    {
        $this->actingAs($this->admin)->get($this->url('%_unknown'))
            ->assertOk()->assertViewHas('frameworks', fn ($items) => $items->isEmpty())
            ->assertSeeText(__('lf.LF_learning_index_no_results'))
            ->assertSeeText(__('lf.LF_learning_index_clear'))
            ->assertSeeText(__('lf.LF_learning_create_framework'))
            ->assertDontSeeText(__('lf.LF_learning_empty_help'));
    }

    public function test_invalid_keyword_is_rejected(): void
    {
        foreach ([['bad'], str_repeat('a', 256)] as $keyword) {
            $this->actingAs($this->admin)->getJson($this->url($keyword))
                ->assertUnprocessable()->assertJsonValidationErrors('keyword');
        }
    }

    public function test_non_latin_search_does_not_turn_into_an_empty_match(): void
    {
        DB::table('core_learning_frameworks')->where('code', 'KOR-01')->update(['description' => 'Lộ trình 한국어']);
        $this->actingAs($this->admin)->get($this->url('한국어'))->assertOk()
            ->assertViewHas('frameworks', fn ($items) => $items->count() === 1 && $items->first()->code === 'KOR-01');
        $this->actingAs($this->admin)->get($this->url('🎓'))->assertOk()
            ->assertViewHas('frameworks', fn ($items) => $items->isEmpty());
    }

    public function test_teacher_cannot_search_the_admin_list(): void
    {
        $this->admin->forceFill(['role' => 'teacher'])->save();
        $this->actingAs($this->admin)->get($this->url('TOPIK'))->assertForbidden();
    }

    public function test_create_opens_a_popup_from_the_list_with_the_full_scale(): void
    {
        $this->actingAs($this->admin)->get(self::LIST_URL)->assertOk()
            ->assertSee('data-url="'.self::LIST_URL.'/create"', false)
            ->assertSee('data-ui-dialog-form ', false);

        $this->actingAs($this->admin)->get(self::LIST_URL.'/create', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSeeInOrder([__('lf.LF_common_button_cancel'), __('lf.LF_common_button_confirm')])
            ->assertSee('name="mastery_scale[levels][0][threshold]"', false)
            ->assertSee('name="mastery_scale[levels][3][key]"', false)
            ->assertSee('value="0.75"', false);
        $this->assertDatabaseCount('core_learning_frameworks', 3);
    }

    public function test_a_popup_create_error_comes_back_as_json_on_its_field(): void
    {
        $this->actingAs($this->admin)->postJson(self::LIST_URL, [
            'name' => '', 'mastery_scale_key' => 'lf-default', 'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [
                ['key' => 'a', 'threshold' => '0'],
                ['key' => 'b', 'threshold' => ''],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'mastery_scale.levels.1.threshold']);
        $this->assertDatabaseCount('core_learning_frameworks', 3);
    }

    public function test_a_teacher_cannot_open_the_framework_popups(): void
    {
        $this->admin->forceFill(['role' => 'teacher'])->save();
        $id = (int) DB::table('core_learning_frameworks')->where('code', 'KOR-01')->value('id');

        foreach (['/create', "/{$id}/form", "/{$id}/scale/form", "/{$id}/competencies/create", "/{$id}/definitions/1/form"] as $path) {
            $this->actingAs($this->admin)->get(self::LIST_URL.$path, ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
        }
    }

    public function test_a_popup_cannot_load_a_framework_of_another_tenant(): void
    {
        $foreign = (int) DB::table('core_learning_frameworks')->where('code', 'KOR-PRIVATE')->value('id');

        $this->actingAs($this->admin)->get(self::LIST_URL."/{$foreign}/form", ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
    }

    public function test_list_has_a_title_and_description(): void
    {
        $this->actingAs($this->admin)->get(self::LIST_URL)->assertOk()
            ->assertSeeInOrder([__('lf.LF_learning_index_title'), __('lf.LF_learning_manual_help'), 'framework-keyword']);
    }

    public function test_list_is_paginated_and_a_page_past_the_end_shows_the_last_page(): void
    {
        $tenant = (int) $this->admin->customer_id;
        foreach (range(1, 10) as $number) {
            DB::table('core_learning_frameworks')->insert([
                'customer_id' => $tenant, 'name' => sprintf('Bộ %02d', $number), 'code' => "P-{$number}", 'status' => 'active',
            ]);
        }

        $this->actingAs($this->admin)->get(self::LIST_URL)->assertOk()
            ->assertViewHas('totalCount', 12)
            ->assertViewHas('frameworks', fn ($items) => $items->count() === 10 && $items->total() === 12);

        foreach (['?page=2', '?page=99'] as $query) {
            $this->actingAs($this->admin)->get(self::LIST_URL.$query)->assertOk()
                ->assertViewHas('frameworks', fn ($items) => $items->count() === 2 && $items->currentPage() === 2)
                ->assertSee('<td class="mk-sequence" data-label="'.__('lf.table_no').'">11</td>', false);
        }
    }

    public function test_only_a_framework_without_versions_or_definitions_can_be_selected_for_delete(): void
    {
        [$used, $defined, $empty] = $this->frameworksInUse();

        $response = $this->actingAs($this->admin)->get(self::LIST_URL)->assertOk();

        $response->assertSee('value="'.$empty.'"', false)
            ->assertDontSee('value="'.$used.'"', false)
            ->assertDontSee('value="'.$defined.'"', false)
            ->assertSee(__('lf.LF_learning_delete_blocked'));
    }

    public function test_admin_can_delete_frameworks_without_versions_or_definitions(): void
    {
        [, , $empty] = $this->frameworksInUse();

        $this->actingAs($this->admin)->deleteJson(self::LIST_URL, ['ids' => [$empty]])
            ->assertOk()->assertJsonPath('message', trans_choice('lf.LF_learning_bulk_deleted', 1, ['count' => 1]));

        $this->assertDatabaseMissing('core_learning_frameworks', ['id' => $empty]);
    }

    public function test_delete_is_all_or_nothing_when_a_framework_is_in_use(): void
    {
        [$used, $defined, $empty] = $this->frameworksInUse();

        foreach ([$used, $defined] as $blocked) {
            $this->actingAs($this->admin)->deleteJson(self::LIST_URL, ['ids' => [$empty, $blocked]])
                ->assertStatus(409)->assertJsonPath('message', __('lf.LF_learning_delete_blocked_stale'));
            $this->assertDatabaseHas('core_learning_frameworks', ['id' => $blocked]);
        }
        $this->assertDatabaseHas('core_learning_frameworks', ['id' => $empty]);
    }

    public function test_delete_cannot_reach_another_tenant_or_be_used_by_a_teacher(): void
    {
        [, , $empty] = $this->frameworksInUse();
        $foreign = (int) DB::table('core_learning_frameworks')->where('code', 'KOR-PRIVATE')->value('id');

        $this->actingAs($this->admin)->deleteJson(self::LIST_URL, ['ids' => [$empty, $foreign]])->assertNotFound();
        $this->assertDatabaseHas('core_learning_frameworks', ['id' => $foreign]);
        $this->assertDatabaseHas('core_learning_frameworks', ['id' => $empty]);

        $this->admin->forceFill(['role' => 'teacher'])->save();
        $this->actingAs($this->admin)->deleteJson(self::LIST_URL, ['ids' => [$empty]])->assertForbidden();
        $this->assertDatabaseHas('core_learning_frameworks', ['id' => $empty]);
    }

    /** @return array{int, int, int} one Framework with a Version, one with a Definition, one with neither */
    private function frameworksInUse(): array
    {
        $tenant = (int) $this->admin->customer_id;
        $used = (int) DB::table('core_learning_frameworks')->where('code', 'KOR-01')->value('id');
        $defined = (int) DB::table('core_learning_frameworks')->where('code', 'DIG-01')->value('id');
        $empty = (int) DB::table('core_learning_frameworks')->insertGetId([
            'customer_id' => $tenant, 'name' => 'Bộ trống', 'code' => 'EMPTY-01', 'status' => 'active',
        ]);
        DB::table('core_learning_framework_versions')->insert([
            'customer_id' => $tenant, 'framework_id' => $used, 'version_number' => 1, 'status' => 'published',
        ]);
        DB::table('core_learning_node_definitions')->insert(['customer_id' => $tenant, 'framework_id' => $defined]);

        return [$used, $defined, $empty];
    }

    private function url(string|array $keyword): string
    {
        return 'https://framework-search.localhost/admin/learning-frameworks?'.http_build_query(['keyword' => $keyword]);
    }

    private function tenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
