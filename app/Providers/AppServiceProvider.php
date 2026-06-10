<?php

namespace App\Providers;

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
        //
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
