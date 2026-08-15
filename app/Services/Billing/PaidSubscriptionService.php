<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\BillingConfiguration;

class PaidSubscriptionService
{
    public function __construct(private readonly BillingConfiguration $billing) {}

    public function subscription(Tenant $tenant): ?Subscription
    {
        $subscription = $tenant->entitledSubscription();

        if (! $subscription?->active() || ! $subscription->plan) {
            return null;
        }

        if ($subscription->plan->price($subscription->billing_cycle) <= 0) {
            return null;
        }

        if ($this->billing->requiresPaymentGateway() && blank($subscription->stripe_subscription_id)) {
            return null;
        }

        return $subscription;
    }

    public function allows(Tenant $tenant): bool
    {
        return $this->subscription($tenant) !== null;
    }
}
