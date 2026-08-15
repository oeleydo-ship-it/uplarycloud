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
                route('billing.index', ['checkout' => 'success']),
                route('billing.index', ['checkout' => 'canceled']),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['billing' => $e->getMessage()]);
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
            $url = $billing->portal($context->current(), route('billing.index'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['billing' => $e->getMessage()]);
        }

        return $url ? redirect()->away($url) : back()->with('success', 'Local billing mode has no external customer portal.');
    }

    public function cancel(Request $request, TenantContext $context, BillingService $billing): RedirectResponse
    {
        $this->manage($request);
        try {
            $billing->cancel($context->current(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['billing' => $e->getMessage()]);
        }

        return back()->with('success', 'Cancellation is scheduled for the end of the billing period.');
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
