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

    public function test_invalid_tenant_404_navigation_returns_to_root_public_site(): void
    {
        $response = $this->get('http://unknown.localhost:8000/login')
            ->assertNotFound();

        foreach ([
            'http://localhost:8000/',
            'http://localhost:8000/features',
            'http://localhost:8000/pricing',
            'http://localhost:8000/services',
            'http://localhost:8000/about',
            'http://localhost:8000/register-customer',
        ] as $url) {
            $response->assertSee('href="'.$url.'"', false);
        }

        $response
            ->assertSee('action="http://localhost:8000/language/en"', false)
            ->assertDontSee('href="http://unknown.localhost:8000/', false)
            ->assertDontSee('action="http://unknown.localhost:8000/', false)
            // @vite() and asset() (used for the compiled CSS/JS and the
            // language-switcher icon) resolve independently of the
            // hand-written nav links above and previously leaked the
            // invalid subdomain into src="" the same way.
            ->assertDontSee('src="http://unknown.localhost:8000/', false);
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
