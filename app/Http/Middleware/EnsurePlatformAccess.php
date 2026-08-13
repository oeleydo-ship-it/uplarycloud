<?php

namespace App\Http\Middleware;

use App\Support\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAccess
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_super_admin || $request->routeIs('logout')) {
            return $next($request);
        }

        if ((bool) ((int) $this->settings->get('general', 'maintenance_mode', 0))) {
            return response()->view('commercial.platform-maintenance', [
                'message' => $this->settings->get('general', 'maintenance_message', 'We are completing scheduled maintenance. Please check back shortly.'),
            ], 503);
        }

        if (! $request->isMethodSafe() && (bool) ((int) $this->settings->get('general', 'read_only_mode', 0))) {
            if ($request->expectsJson()) {
                abort(423, 'The platform is temporarily in read-only mode.');
            }

            return back()->with('error', 'The platform is temporarily in read-only mode. No changes were saved.');
        }

        return $next($request);
    }
}
