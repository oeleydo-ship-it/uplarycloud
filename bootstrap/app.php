<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\SetCurrentTenant;
use App\Http\Middleware\EnsureApiTokenTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureApplicationInstalled;

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
        $middleware->alias(['tenant' => SetCurrentTenant::class, 'api.tenant' => EnsureApiTokenTenant::class, 'superadmin' => EnsureSuperAdmin::class]);
        $middleware->validateCsrfTokens(except: ['hooks/git/*', 'stripe/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
