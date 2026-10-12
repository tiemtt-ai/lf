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

    public function test_create_form_has_cancel_before_submit_and_cancel_returns_to_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('https://framework-search.localhost/admin/learning-frameworks/create')
            ->assertOk()->assertSeeInOrder([
                'lf-framework-create__actions', __('lf.LF_common_button_cancel'), __('lf.LF_learning_create_framework'),
            ]);
        $this->assertStringContainsString('type="submit"', $response->getContent());
        $this->get('https://framework-search.localhost/admin/learning-frameworks')->assertOk();
        $this->assertDatabaseCount('core_learning_frameworks', 3);
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
