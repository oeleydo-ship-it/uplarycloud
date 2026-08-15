<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\ManagedServerCheckoutService;
use App\Support\BillingConfiguration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, ManagedServerCheckoutService $managedCheckout, BillingConfiguration $billing): Response
    {
        $webhookSecret = $billing->stripeWebhookSecret();
        abort_unless($webhookSecret, 503, 'Stripe webhook is not configured.');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $webhookSecret,
            );
        } catch (\Throwable) {
            abort(400, 'Invalid Stripe webhook signature.');
        }

        $object = $event->data->object;
        match ($event->type) {
            'checkout.session.completed' => $this->checkout($object, $managedCheckout, $billing),
            'customer.subscription.updated', 'customer.subscription.deleted' => $this->subscription($object),
            'invoice.paid', 'invoice.payment_failed' => $this->invoice($object),
            default => null,
        };

        return response('accepted');
    }

    private function checkout(object $session, ManagedServerCheckoutService $managedCheckout, BillingConfiguration $billing): void
    {
        if (($session->metadata->type ?? null) === 'managed_server_order') {
            $managedCheckout->markPaidFromCheckoutSession($session);

            return;
        }

        $tenant = Tenant::find($session->metadata->tenant_id ?? null);
        $plan = Plan::find($session->metadata->plan_id ?? null);
        if (! $tenant || ! $plan) {
            return;
        }

        $periodStart = now();
        $periodEnd = null;
        if ($billing->requiresPaymentGateway() && filled($session->subscription ?? null)) {
            $stripe = new StripeClient($billing->stripeSecret());
            $stripeSubscription = $stripe->subscriptions->retrieve($session->subscription);
            $periodStart = isset($stripeSubscription->current_period_start)
                ? Carbon::createFromTimestamp($stripeSubscription->current_period_start)
                : now();
            $periodEnd = isset($stripeSubscription->current_period_end)
                ? Carbon::createFromTimestamp($stripeSubscription->current_period_end)
                : null;
        }

        $tenant->subscriptions()->whereIn('status', ['active', 'trialing', 'past_due'])->update([
            'status' => 'canceled',
            'ended_at' => now(),
        ]);

        Subscription::updateOrCreate(
            ['stripe_subscription_id' => $session->subscription],
            [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_cycle' => $session->metadata->billing_cycle ?? 'monthly',
                'stripe_customer_id' => $session->customer,
                'current_period_starts_at' => $periodStart,
                'current_period_ends_at' => $periodEnd,
                'metadata' => ['gateway' => 'stripe'],
            ],
        );
    }

    private function subscription(object $stripe): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripe->id)->first();
        if (! $subscription) {
            return;
        }

        $subscription->update([
            'status' => $stripe->status,
            'current_period_starts_at' => isset($stripe->current_period_start)
                ? Carbon::createFromTimestamp($stripe->current_period_start)
                : $subscription->current_period_starts_at,
            'current_period_ends_at' => isset($stripe->current_period_end)
                ? Carbon::createFromTimestamp($stripe->current_period_end)
                : null,
            'cancel_at' => isset($stripe->cancel_at) && $stripe->cancel_at
                ? Carbon::createFromTimestamp($stripe->cancel_at)
                : null,
            'ended_at' => isset($stripe->ended_at) && $stripe->ended_at
                ? Carbon::createFromTimestamp($stripe->ended_at)
                : null,
        ]);
    }

    private function invoice(object $stripe): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripe->subscription ?? null)->first();
        if (! $subscription) {
            return;
        }

        BillingInvoice::updateOrCreate(
            ['stripe_invoice_id' => $stripe->id],
            [
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'number' => $stripe->number,
                'status' => $stripe->status,
                'currency' => strtoupper($stripe->currency),
                'subtotal' => $stripe->subtotal,
                'tax' => $stripe->tax ?? 0,
                'total' => $stripe->total,
                'hosted_invoice_url' => $stripe->hosted_invoice_url,
                'invoice_pdf' => $stripe->invoice_pdf,
                'paid_at' => $stripe->status === 'paid' ? now() : null,
                'due_at' => isset($stripe->due_date) && $stripe->due_date
                    ? Carbon::createFromTimestamp($stripe->due_date)
                    : null,
            ],
        );

        if ($stripe->status !== 'paid') {
            $subscription->update(['status' => 'past_due']);
        }
    }
}
