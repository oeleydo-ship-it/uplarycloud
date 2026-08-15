<?php

namespace App\Services\Billing;

use App\Contracts\Billing\BillingGatewayInterface;
use App\Models\ActivityLog;
use App\Models\BillingInvoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BillingConfiguration;
use Illuminate\Support\Str;
use RuntimeException;

class BillingService
{
    public function __construct(
        private readonly BillingGatewayInterface $gateway,
        private readonly BillingConfiguration $billing,
    ) {}

    public function subscribe(Tenant $tenant, Plan $plan, string $cycle, User $actor, string $successUrl, string $cancelUrl): ?string
    {
        $price = $plan->price($cycle);

        if ($price <= 0) {
            $this->activateLocally($tenant, $plan, $cycle, $actor, 'free');

            return null;
        }

        $url = $this->gateway->checkout($tenant, $plan, $cycle, $successUrl, $cancelUrl);
        if ($url) {
            return $url;
        }

        if (! $this->billing->allowsInstantActivation()) {
            throw new RuntimeException('Online payment is required to activate this plan. Configure Stripe billing in platform settings or contact support.');
        }

        $this->activateLocally($tenant, $plan, $cycle, $actor, 'fake');

        return null;
    }

    public function portal(Tenant $tenant, string $returnUrl): ?string
    {
        return $this->gateway->portal($tenant, $returnUrl);
    }

    public function cancel(Tenant $tenant, User $actor): void
    {
        $subscription = $tenant->currentSubscription();
        if (! $subscription) {
            return;
        }

        $this->gateway->cancel($tenant);
        $subscription->update(['cancel_at' => $subscription->current_period_ends_at]);
        ActivityLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'action' => 'billing.subscription.canceled',
            'description' => 'Subscription cancellation scheduled',
        ]);
    }

    private function activateLocally(Tenant $tenant, Plan $plan, string $cycle, User $actor, string $gateway): void
    {
        $now = now();
        $tenant->subscriptions()->whereIn('status', ['active', 'trialing', 'past_due'])->update([
            'status' => 'canceled',
            'ended_at' => $now,
        ]);

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => $cycle,
            'current_period_starts_at' => $now,
            'current_period_ends_at' => $cycle === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth(),
            'metadata' => ['gateway' => $gateway],
        ]);

        if ($plan->price($cycle) > 0 && $gateway === 'fake') {
            $price = $plan->price($cycle);
            BillingInvoice::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'number' => 'INV-'.now()->format('Ym').'-'.Str::upper(Str::random(6)),
                'status' => 'paid',
                'currency' => $plan->currency,
                'subtotal' => $price,
                'total' => $price,
                'paid_at' => $now,
                'line_items' => [['description' => $plan->name.' plan ('.$cycle.')', 'amount' => $price]],
            ]);
            PaymentMethod::firstOrCreate(
                ['tenant_id' => $tenant->id, 'is_default' => true],
                ['type' => 'card', 'brand' => 'Visa', 'last_four' => '4242', 'expiry_month' => 12, 'expiry_year' => now()->year + 3]
            );
        }

        ActivityLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'action' => 'billing.subscription.updated',
            'description' => 'Subscription changed to '.$plan->name,
            'metadata' => ['plan' => $plan->slug, 'cycle' => $cycle, 'gateway' => $gateway],
        ]);
    }
}
