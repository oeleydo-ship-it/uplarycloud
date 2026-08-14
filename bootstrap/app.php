<?php

use App\Http\Middleware\EnsureApiTokenTenant;
use App\Http\Middleware\EnsureApplicationInstalled;
use App\Http\Middleware\EnsurePlatformAccess;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsurePaidSubscription;
use App\Http\Middleware\EnsurePlatformFeature;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(EnsureApplicationInstalled::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['tenant' => SetCurrentTenant::class, 'platform.access' => EnsurePlatformAccess::class, 'api.tenant' => EnsureApiTokenTenant::class, 'superadmin' => EnsureSuperAdmin::class, 'plan.feature' => EnsurePlanFeature::class, 'paid.subscription' => EnsurePaidSubscription::class, 'platform.feature' => EnsurePlatformFeature::class]);
        $middleware->validateCsrfTokens(except: ['hooks/git/*', 'stripe/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
