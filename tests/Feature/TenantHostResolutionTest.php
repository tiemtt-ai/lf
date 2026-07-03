<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantHostResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'http://localhost:8000',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'http',
        ]);
    }

    public function test_root_domain_displays_the_root_landing_page(): void
    {
        $this->get('http://localhost:8000/')
            ->assertOk()
            ->assertViewIs('pages.home')
            ->assertSeeText(__('lf.LF_home_public_title'));

        $this->assertNull(TenantContext::customer());
        $this->assertNull(TenantContext::customerId());
    }

    public function test_valid_tenant_subdomain_displays_the_tenant_website(): void
    {
        $customerId = $this->createTenant('tenant-a');

        $this->get('http://tenant-a.localhost:8000/')
            ->assertOk()
            ->assertViewIs('tenant.home')
            ->assertViewHas('tenant', fn ($tenant): bool => (
                (int) $tenant->id === $customerId
            ))
            ->assertSeeText('Tenant A');

        $this->assertSame($customerId, TenantContext::customerId());
    }

    public function test_invalid_tenant_subdomain_returns_not_found_and_clears_context(): void
    {
        $this->createTenant('tenant-a');

        $this->get('http://tenant-a.localhost:8000/')->assertOk();
        $this->assertNotNull(TenantContext::customerId());

        $this->get('http://unknown.localhost:8000/')
            ->assertNotFound();

        $this->assertNull(TenantContext::customer());
        $this->assertNull(TenantContext::customerId());
    }

    public function test_invalid_tenant_subdomain_does_not_display_root_pages(): void
    {
        $this->get('http://unknown.localhost:8000/')
            ->assertNotFound()
            ->assertDontSeeText(__('lf.LF_home_public_title'));

        $this->get('http://unknown.localhost:8000/features')
            ->assertNotFound()
            ->assertDontSeeText(__('lf.LF_home_message_public_features_title'));

        $this->get('http://localhost:8000/features')
            ->assertOk()
            ->assertViewIs('pages.features');
    }

    private function createTenant(string $subdomain): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => 'Tenant A',
            'slug' => $subdomain,
            'subdomain' => $subdomain,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
