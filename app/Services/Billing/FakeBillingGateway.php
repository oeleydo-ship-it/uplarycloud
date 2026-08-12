<?php
namespace App\Services\Billing;
use App\Contracts\Billing\BillingGatewayInterface;use App\Models\Plan;use App\Models\Tenant;
class FakeBillingGateway implements BillingGatewayInterface{public function checkout(Tenant $tenant,Plan $plan,string $cycle,string $successUrl,string $cancelUrl):?string{return null;}public function portal(Tenant $tenant,string $returnUrl):?string{return null;}public function cancel(Tenant $tenant):void{}}
