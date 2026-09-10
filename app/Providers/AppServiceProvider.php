<?php

namespace App\Providers;

use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\Ai\UnavailableCommercialEntitlements;
use App\Services\Ai\UnavailableTenantSettings;
use App\Services\Ai\UnavailableUsageQuotaReserver;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AI provider execution gate — LF-AI § "Provider execution gate".
        //
        // Every dependency the gate cannot answer for itself is bound to a
        // fail-closed default, because the stores that would answer them
        // (`saas_customer_settings`, `saas_entitlements`, `saas_usage_counters`)
        // are all still `not_implemented`. Binding a permissive stub here would
        // turn "we cannot check" into "checked and fine", which is the exact
        // failure mode ADR-0018 requires us to avoid.
        $this->app->bind(TenantSettingSource::class, UnavailableTenantSettings::class);
        $this->app->bind(ExternalProcessingApprovals::class, SettingBackedExternalProcessingApprovals::class);
        $this->app->bind(CommercialEntitlements::class, UnavailableCommercialEntitlements::class);
        $this->app->bind(UsageQuotaReserver::class, UnavailableUsageQuotaReserver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('password-reset', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));
            $host = Str::lower($request->getHost());
            $baseDomain = Str::lower((string) config('app.base_domain'));

            $customer = DB::table('saas_customers')
                ->where('custom_domain', $host)
                ->where('status', 'active')
                ->first(['id']);

            if (! $customer && str_ends_with($host, '.'.$baseDomain)) {
                $subdomain = substr($host, 0, -strlen('.'.$baseDomain));

                $customer = DB::table('saas_customers')
                    ->where('subdomain', $subdomain)
                    ->where('status', 'active')
                    ->first(['id']);
            }

            $key = implode('|', [
                $customer?->id ?? 'no-tenant',
                $email,
                $request->ip(),
            ]);

            return Limit::perMinute(5)->by(hash('sha256', $key));
        });

        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            $customer = DB::table('saas_customers')
                ->where('id', $notifiable->customer_id)
                ->first(['subdomain', 'custom_domain']);

            abort_if(! $customer, 404);

            $host = $customer->custom_domain
                ?: $customer->subdomain.'.'.config('app.base_domain');
            $port = parse_url(config('app.url'), PHP_URL_PORT);
            $rootUrl = config('app.tenant_scheme', 'https').'://'.$host.($port ? ':'.$port : '');

            $url = clone URL::getFacadeRoot();
            $url->forceRootUrl($rootUrl);
            $url->forceScheme(config('app.tenant_scheme', 'https'));

            return $url->temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });
    }
}
