<?php

namespace App\Http\Middleware;

use App\Services\Billing\PaidSubscriptionService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePaidSubscription
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PaidSubscriptionService $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->has() || $this->access->allows($this->context->current())) {
            return $next($request);
        }

        $message = 'An active paid subscription is required to create and operate managed servers.';

        if ($request->expectsJson()) {
            abort(402, $message);
        }

        if ($request->isMethod('GET')) {
            return response()->view('commercial.payment-required', [
                'message' => $message,
                'canManageBilling' => $this->canManageBilling($request),
            ], 402);
        }

        return redirect()->route('billing.index')->withErrors(['payment' => $message]);
    }

    private function canManageBilling(Request $request): bool
    {
        $role = $request->user()?->tenants()->whereKey(session('tenant_id'))->first()?->pivot->role;

        return in_array($role, ['owner', 'billing'], true);
    }
}
