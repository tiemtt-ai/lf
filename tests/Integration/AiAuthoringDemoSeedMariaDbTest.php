<?php

namespace Tests\Integration;

use App\Contracts\Ai\TenantSettingSource;
use App\Support\AiDemo\InMemoryTenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `ai:authoring-demo-seed` is local-only demo data for the review UI. What
 * matters is what it must never do: run outside local/testing, leave any
 * approval or allow-list behind, or change anything on a second run.
 */
class AiAuthoringDemoSeedMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'ai-demo-local-only';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The demo tenant needs the Learning Foundation schema, which only exists on MariaDB.');
        }
        Storage::fake('media_local');
        // Storage::fake swaps the disk object but leaves its configured root pointing at the
        // real media folder; the command reads that root, so point it at the fake's own folder.
        config([
            'media.disk' => 'media_local', 'media.bucket' => 'test-media',
            'filesystems.disks.media_local.root' => Storage::disk('media_local')->path(''),
        ]);
    }

    public function test_it_refuses_to_run_outside_local_and_testing_before_writing_anything(): void
    {
        $tenants = DB::table('saas_customers')->count();
        $users = DB::table('users')->count();

        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;

            $this->assertSame(1, Artisan::call('ai:authoring-demo-seed'), $environment.' must be refused.');
            $this->assertStringContainsString('only runs in the local and testing environments', Artisan::output());
        }

        $this->assertSame($tenants, DB::table('saas_customers')->count());
        $this->assertSame($users, DB::table('users')->count());
    }

    public function test_it_builds_a_synthetic_tenant_with_proposals_in_several_states(): void
    {
        $exit = Artisan::call('ai:authoring-demo-seed');
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);

        $tenant = DB::table('saas_customers')->where('slug', 'ai-demo')->sole();
        $states = DB::table('ai_authoring_proposals')->where('customer_id', $tenant->id)->pluck('status')->countBy();
        $this->assertGreaterThan(3, $states->sum());
        $this->assertSame(1, $states['accepted'] ?? 0);
        $this->assertSame(1, $states['rejected'] ?? 0);
        $this->assertGreaterThan(0, $states['pending_review'] ?? 0);
        $this->assertEqualsCanonicalizing(
            ['summary', 'concept', 'learning_objective', 'competency', 'node_mapping'],
            DB::table('ai_authoring_proposals')->where('customer_id', $tenant->id)->distinct()->pluck('kind')->all(),
        );
        // Both mapping modes exist, so the UI has to handle each.
        $modes = DB::table('ai_authoring_proposals as p')
            ->join('ai_authoring_proposal_revisions as r', function ($join): void {
                $join->on('r.proposal_id', '=', 'p.id')->on('r.customer_id', '=', 'p.customer_id');
            })->where('p.customer_id', $tenant->id)->where('p.kind', 'node_mapping')->pluck('r.payload')
            ->map(fn ($payload) => json_decode($payload, true)['mapping']['mode'])->unique()->sort()->values()->all();
        $this->assertSame(['propose_new', 'reuse_existing'], $modes);

        // It went through the real Commercial ledger, once, for the one generation.
        $this->assertSame(1, DB::table('saas_usage_events')->where('customer_id', $tenant->id)->count());
        // The demo accounts and host are reported; the password is not.
        $this->assertStringContainsString('ai-demo.localhost', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        $this->assertTrue(password_verify(self::PASSWORD, DB::table('users')->where('customer_id', $tenant->id)->where('role', 'teacher')->value('password')));
    }

    public function test_it_never_writes_into_a_media_folder_that_already_exists(): void
    {
        // Ids the command will get; a folder already there belongs to something else.
        [$nextTenant, $nextActivity] = $this->nextIds();
        $folder = 'tenants/'.$nextTenant.'/course/activities/'.$nextActivity;
        Storage::disk('media_local')->put($folder.'/keep.txt', 'not the demo\'s');

        $this->assertSame(1, Artisan::call('ai:authoring-demo-seed'));

        $this->assertStringContainsString('must be removed by hand', Artisan::output());
        $this->assertSame([$folder.'/keep.txt'], Storage::disk('media_local')->allFiles($folder));
        $this->assertSame('not the demo\'s', Storage::disk('media_local')->get($folder.'/keep.txt'));
        $this->assertSame(0, DB::table('ai_authoring_proposals')->count());
    }

    public function test_an_existing_tenant_folder_is_left_alone_even_when_the_activity_folder_is_new(): void
    {
        // Another database's tenant with the same number: its folder exists, the demo
        // activity's path inside it does not. Checking only that leaf would write there.
        [$nextTenant] = $this->nextIds();
        $canary = 'tenants/'.$nextTenant.'/other-owner/keep.txt';
        Storage::disk('media_local')->put($canary, 'belongs to someone else');
        $before = Storage::disk('media_local')->allFiles();

        $this->assertSame(1, Artisan::call('ai:authoring-demo-seed'));

        $this->assertStringContainsString('must be removed by hand', Artisan::output());
        $this->assertSame($before, Storage::disk('media_local')->allFiles(), 'Nothing may be added to a tenant folder that already exists.');
        $this->assertSame('belongs to someone else', Storage::disk('media_local')->get($canary));
        $this->assertSame(0, DB::table('ai_authoring_proposals')->count());
    }

    public function test_a_media_disk_that_is_not_local_is_refused_before_anything_is_touched(): void
    {
        // A stand-in for a remote driver: if anything asked for the disk, this would run.
        $built = false;
        Storage::extend('remote_sentinel', function () use (&$built) {
            $built = true;

            throw new \LogicException('The remote disk must never be reached.');
        });
        config([
            'media.disk' => 'media_remote_sentinel',
            'filesystems.disks.media_remote_sentinel' => ['driver' => 'remote_sentinel', 'root' => '/never-used'],
        ]);
        $tenants = DB::table('saas_customers')->count();

        $this->assertSame(1, Artisan::call('ai:authoring-demo-seed'));

        $this->assertStringContainsString('only writes media to a local disk', Artisan::output());
        $this->assertFalse($built, 'The remote disk was reached.');
        $this->assertSame($tenants, DB::table('saas_customers')->count());
        $this->assertSame(0, DB::table('ai_authoring_proposals')->count());
    }

    /** @return array{0:int,1:int} The tenant and activity ids the next run will be given. */
    private function nextIds(): array
    {
        $next = fn (string $table): int => (int) DB::selectOne('SELECT AUTO_INCREMENT AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->n;

        return [$next('saas_customers'), $next('core_course_template_activities')];
    }

    public function test_it_leaves_no_approval_or_allow_list_behind(): void
    {
        Artisan::call('ai:authoring-demo-seed');

        // The approval lived in an in-memory store, not in any table.
        $this->assertInstanceOf(InMemoryTenantSettings::class, $this->app->make(TenantSettingSource::class));
        if (Schema::hasTable('saas_customer_settings')) {
            $this->assertSame(0, DB::table('saas_customer_settings')->where('setting_group', 'ai')->count());
        }
        // A fresh application, as the next process would have, is back to the shipped default.
        $fresh = $this->createApplication();
        $this->assertSame([], $fresh['config']->get('ai.providers'));
        $this->assertNull($fresh['config']->get('ai.authoring.provider'));
        $this->assertNotInstanceOf(InMemoryTenantSettings::class, $fresh->make(TenantSettingSource::class));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        Artisan::call('ai:authoring-demo-seed');
        $counts = fn (): array => [
            DB::table('saas_customers')->count(), DB::table('users')->count(),
            DB::table('ai_authoring_proposals')->count(), DB::table('saas_usage_events')->count(),
            DB::table('ai_model_runs')->count(),
        ];
        $before = $counts();

        $this->assertSame(0, Artisan::call('ai:authoring-demo-seed'));

        $this->assertStringContainsString('already exists', Artisan::output());
        $this->assertSame($before, $counts());
    }
}
