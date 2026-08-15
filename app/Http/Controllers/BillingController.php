<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\BillingService;
use App\Services\Billing\UsageService;
use App\Support\BillingConfiguration;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class BillingController extends Controller
{
    public function index(Request $request, TenantContext $context, UsageService $usage, BillingConfiguration $billing): View
    {
        $this->access($request);
        $tenant = $context->current();

        return view('commercial.billing', [
            'plans' => Plan::where('active', true)->orderBy('position')->get(),
            'subscription' => $tenant->currentSubscription(),
            'invoices' => $tenant->invoices()->latest()->limit(12)->get(),
            'paymentMethods' => $tenant->paymentMethods()->get(),
            'usage' => $usage->latest($tenant),
            'infrastructureCharges' => $tenant->infrastructureCharges()->with('server')->latest()->limit(24)->get(),
            'billingConfig' => $billing,
        ]);
    }

    public function subscribe(Request $request, TenantContext $context, BillingService $billing): RedirectResponse
    {
        $this->manage($request);
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('active', true)],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
        ]);
        $plan = Plan::findOrFail($data['plan_id']);

        try {
            $url = $billing->subscribe(
                $context->current(),
                $plan,
                $data['billing_cycle'],
                $request->user(),
                $this->checkoutReturnUrl($request, 'success'),
                $this->checkoutReturnUrl($request, 'canceled'),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'billing' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to start checkout. Please try again or contact support.',
            ]);
        }

        if ($url) {
            return redirect()->away($url);
        }

        $message = $plan->price($data['billing_cycle']) <= 0
            ? 'Your workspace is now on the '.$plan->name.' plan.'
            : 'Your subscription is now active.';

        return back()->with('success', $message);
    }

    public function portal(Request $request, TenantContext $context, BillingService $billing, BillingConfiguration $billingConfig): RedirectResponse
    {
        $this->manage($request);

        if (! $billingConfig->requiresPaymentGateway()) {
            return back()->withErrors(['billing' => 'The customer portal is only available when Stripe billing is configured.']);
        }

        try {
            $url = $billing->portal($context->current(), $this->checkoutReturnUrl($request));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'billing' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to open the billing portal.',
            ]);
        }

        return $url ? redirect()->away($url) : back()->with('success', 'Local billing mode has no external customer portal.');
    }

    public function cancel(Request $request, TenantContext $context, BillingService $billing): RedirectResponse
    {
        $this->manage($request);
        try {
            $billing->cancel($context->current(), $request->user());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'billing' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to cancel the subscription right now.',
            ]);
        }

        return back()->with('success', 'Cancellation is scheduled for the end of the billing period.');
    }

    private function checkoutReturnUrl(Request $request, ?string $checkout = null): string
    {
        $path = route('billing.index', array_filter([
            'checkout' => $checkout,
        ]), false);

        return rtrim($request->getSchemeAndHttpHost(), '/').$path;
    }

    private function role(Request $request): ?string
    {
        return $request->user()->tenants()->whereKey(session('tenant_id'))->first()?->pivot->role;
    }

    private function access(Request $request): void
    {
        abort_unless(in_array($this->role($request), ['owner', 'billing'], true), 403);
    }

    private function manage(Request $request): void
    {
        $this->access($request);
    }
}
