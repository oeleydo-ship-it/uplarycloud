<?php

namespace App\Providers;

use App\Contracts\Billing\BillingGatewayInterface;
use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Contracts\Networking\DnsResolverInterface;
use App\Models\PersonalAccessToken;
use App\Services\Billing\FakeBillingGateway;
use App\Services\Billing\StripeBillingGateway;
use App\Services\Infrastructure\FakeServerExecutor;
use App\Services\Infrastructure\SSHServerExecutor;
use App\Services\Networking\FakeDnsResolver;
use App\Services\Networking\SystemDnsResolver;
use App\Support\Branding;
use App\Support\CurrentPlanAccess;
use App\Support\InstallationState;
use App\Support\TenantContext;
use App\Support\WorkspaceSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CurrentPlanAccess::class);
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
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('contact', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
        View::composer('*', function ($view): void {
            $context = app(TenantContext::class);
            if ($context->has() && ! isset($view['planAccess'])) {
                $view->with('planAccess', app(CurrentPlanAccess::class));
            }
        });
    }
}
