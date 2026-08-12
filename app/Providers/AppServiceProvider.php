<?php

namespace App\Providers;

use App\Support\Branding;
use App\Support\InstallationState;
use App\Support\TenantContext;
use App\Support\WorkspaceSettings;
use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Contracts\Networking\DnsResolverInterface;
use App\Services\Networking\FakeDnsResolver;
use App\Services\Networking\SystemDnsResolver;
use App\Services\Infrastructure\FakeServerExecutor;
use App\Services\Infrastructure\SSHServerExecutor;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Contracts\Billing\BillingGatewayInterface;
use App\Services\Billing\FakeBillingGateway;
use App\Services\Billing\StripeBillingGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(Branding::class);
        $this->app->scoped(WorkspaceSettings::class);
        $this->app->scoped(InstallationState::class);
        $this->app->bind(ServerExecutorInterface::class, fn ($app) => match (config('infrastructure.driver')) {
            'ssh' => $app->make(SSHServerExecutor::class), default => $app->make(FakeServerExecutor::class),
        });
        $this->app->bind(DnsResolverInterface::class, fn ($app) => config('networking.dns_driver') === 'system' ? $app->make(SystemDnsResolver::class) : $app->make(FakeDnsResolver::class));
        $this->app->bind(BillingGatewayInterface::class, fn ($app) => config('billing.driver') === 'stripe' ? $app->make(StripeBillingGateway::class) : $app->make(FakeBillingGateway::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
