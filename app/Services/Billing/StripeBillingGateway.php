<?php

namespace App\Services\Billing;

use App\Contracts\Billing\BillingGatewayInterface;
use App\Models\ManagedServerOrder;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\BillingConfiguration;
use RuntimeException;
use Stripe\StripeClient;

class StripeBillingGateway implements BillingGatewayInterface
{
    private StripeClient $stripe;

    public function __construct(private readonly BillingConfiguration $billing)
    {
        $secret = $this->billing->stripeSecret();
        if (! $secret) {
            throw new RuntimeException('Stripe is not configured.');
        }

        $this->stripe = new StripeClient($secret);
    }

    public function checkout(Tenant $tenant, Plan $plan, string $cycle, string $successUrl, string $cancelUrl): ?string
    {
        $price = $cycle === 'yearly' ? $plan->stripe_yearly_price_id : $plan->stripe_monthly_price_id;
        if (! $price) {
            throw new RuntimeException('This plan does not have a Stripe price configured.');
        }

        $customer = $this->customerId($tenant);
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customer,
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'allow_promotion_codes' => true,
            'metadata' => [
                'type' => 'workspace_subscription',
                'tenant_id' => (string) $tenant->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $cycle,
            ],
            'subscription_data' => [
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                    'plan_id' => (string) $plan->id,
                    'billing_cycle' => $cycle,
                ],
            ],
        ]);

        return $session->url;
    }

    public function checkoutManagedServer(Tenant $tenant, ManagedServerOrder $order, string $successUrl, string $cancelUrl): ?string
    {
        $plan = $order->managedPlan;
        $customer = $this->customerId($tenant);
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $customer,
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($plan->currency),
                    'product_data' => [
                        'name' => $plan->name.' managed server',
                        'description' => 'First month of managed server compute for '.$order->name,
                    ],
                    'unit_amount' => $order->amount,
                ],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'type' => 'managed_server_order',
                'managed_server_order_id' => (string) $order->id,
                'tenant_id' => (string) $tenant->id,
            ],
        ]);

        $order->update(['stripe_checkout_session_id' => $session->id]);

        return $session->url;
    }

    public function portal(Tenant $tenant, string $returnUrl): ?string
    {
        $customer = $tenant->currentSubscription()?->stripe_customer_id;
        if (! $customer) {
            throw new RuntimeException('No Stripe customer is connected.');
        }

        return $this->stripe->billingPortal->sessions->create([
            'customer' => $customer,
            'return_url' => $returnUrl,
        ])->url;
    }

    public function cancel(Tenant $tenant): void
    {
        $subscription = $tenant->currentSubscription();
        if (! $subscription?->stripe_subscription_id) {
            throw new RuntimeException('No Stripe subscription is connected.');
        }

        $this->stripe->subscriptions->update($subscription->stripe_subscription_id, [
            'cancel_at_period_end' => true,
        ]);
    }

    private function customerId(Tenant $tenant): string
    {
        $subscription = $tenant->currentSubscription();
        if ($subscription?->stripe_customer_id) {
            return $subscription->stripe_customer_id;
        }

        $owner = $tenant->users()->wherePivot('role', 'owner')->first();
        $customer = $this->stripe->customers->create([
            'name' => $tenant->name,
            'email' => $owner?->email,
            'metadata' => ['tenant_id' => (string) $tenant->id],
        ])->id;

        if ($subscription) {
            $subscription->update(['stripe_customer_id' => $customer]);
        }

        return $customer;
    }
}
