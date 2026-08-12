<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $tenant = $user->tenants()
            ->wherePivot('is_active', true)
            ->find($request->session()->get('tenant_id'))
            ?? $user->tenants()->wherePivot('is_active', true)->first();

        abort_unless($tenant, 403, 'You do not have access to an active workspace.');

        $request->session()->put('tenant_id', $tenant->id);
        app(TenantContext::class)->set($tenant);

        return $next($request);
    }
}
