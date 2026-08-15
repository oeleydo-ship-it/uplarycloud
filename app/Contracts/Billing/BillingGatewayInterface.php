<?php

namespace App\Contracts\Billing;

use App\Models\ManagedServerOrder;
use App\Models\Plan;
use App\Models\Tenant;

interface BillingGatewayInterface
{
    public function checkout(Tenant $tenant, Plan $plan, string $cycle, string $successUrl, string $cancelUrl): ?string;

    public function checkoutManagedServer(Tenant $tenant, ManagedServerOrder $order, string $successUrl, string $cancelUrl): ?string;

    public function portal(Tenant $tenant, string $returnUrl): ?string;

    public function cancel(Tenant $tenant): void;
}
