<?php

namespace App\Providers;

use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\EmbeddingProvider;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Contracts\Ai\VectorStore;
use App\Services\Ai\DatabaseCommercialEntitlements;
use App\Services\Ai\DatabaseTenantSettings;
use App\Services\Ai\DatabaseUsageQuotaReserver;
use App\Services\Ai\QdrantVectorStore;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\Ai\UnavailableEmbeddingProvider;
use App\Services\Ai\UnavailableVectorStore;
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
        // These read Commercial and Tenant state that is still documented but
        // not migrated. Each implementation checks its table and degrades to the
        // fail-closed answer while it is absent, so the gate keeps producing a
        // clean `blocked` run instead of a QueryException. The `Unavailable*`
        // classes remain as the explicit fail-closed reference and are what
        // tests bind when they need a hard "no".
        $this->app->bind(TenantSettingSource::class, DatabaseTenantSettings::class);
        $this->app->bind(ExternalProcessingApprovals::class, SettingBackedExternalProcessingApprovals::class);
        $this->app->bind(CommercialEntitlements::class, DatabaseCommercialEntitlements::class);
        $this->app->bind(UsageQuotaReserver::class, DatabaseUsageQuotaReserver::class);

        // Embedding + vector store — ADR-0006 v1.0.2.
        //
        // No embedding provider is bound to a real vendor. Activating one is a
        // separate decision under ADR-0018, and shipping a default here would
        // make that decision by omission. The bound provider throws, so an
        // unapproved deployment cannot produce vectors rather than producing
        // wrong ones.
        $this->app->bind(EmbeddingProvider::class, UnavailableEmbeddingProvider::class);

        // The store is different: Qdrant is self-hosted inside the LF boundary,
        // so the adapter itself is safe to bind. It is the *configuration* that
        // gates it — `ai.vector_store.host` ships empty, and an unconfigured
        // deployment falls back to the store that refuses every operation.
        $this->app->bind(VectorStore::class, static fn (): VectorStore => config('ai.vector_store.host')
            ? new QdrantVectorStore
            : new UnavailableVectorStore);
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
