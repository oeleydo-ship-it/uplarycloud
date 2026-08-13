<?php

namespace App\Http\Middleware;

use App\Support\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformFeature
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($request->user()?->is_super_admin || $this->settings->featureEnabled($feature)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            abort(403, 'This feature is currently disabled by the platform administrator.');
        }

        return response()->view('commercial.platform-feature-disabled', ['feature' => $feature], 403);
    }
}
