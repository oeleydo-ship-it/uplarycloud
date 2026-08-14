<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\Tenant;

class PaidSubscriptionService
{
    public function subscription(Tenant $tenant): ?Subscription
    {
        $subscription = $tenant->entitledSubscription();

        if (! $subscription?->active() || ! $subscription->plan) {
            return null;
        }

        return $subscription->plan->price($subscription->billing_cycle) > 0
            ? $subscription
            : null;
    }

    public function allows(Tenant $tenant): bool
    {
        return $this->subscription($tenant) !== null;
    }
}
