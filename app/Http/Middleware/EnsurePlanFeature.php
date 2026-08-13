<?php

namespace App\Http\Middleware;

use App\Services\Billing\PlanLimitService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeature
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlanLimitService $limits,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $this->context->has()) {
            return $next($request);
        }

        if ($this->limits->allowsFeature($this->context->current(), $feature)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'This feature is not included in your current plan.');
        }

        if ($request->isMethod('GET')) {
            return response()->view('commercial.plan-locked', [
                'feature' => $feature,
                'plan' => $this->limits->plan($this->context->current()),
            ]);
        }

        $this->limits->enforceFeature($this->context->current(), $feature);

        abort(403, 'This feature is not included in your current plan.');
    }
}
