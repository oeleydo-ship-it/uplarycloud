<?php

namespace App\Services\Billing;

use App\Contracts\Billing\BillingGatewayInterface;
use App\Models\ManagedServerOrder;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Infrastructure\ManagedServerProvisioningService;
use App\Support\BillingConfiguration;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ManagedServerCheckoutService
{
    public function __construct(
        private readonly BillingGatewayInterface $gateway,
        private readonly BillingConfiguration $billing,
        private readonly ManagedServerProvisioningService $provisioning,
    ) {}

    /**
     * @param  array{name:string,provider_connection_id:int,managed_server_plan_id:int,region:string,image:string}  $data
     */
    public function begin(
        Tenant $tenant,
        User $actor,
        array $data,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $connection = ProviderConnection::query()
            ->where('platform_managed', true)
            ->where('active', true)
            ->whereNotNull('last_verified_at')
            ->findOrFail($data['provider_connection_id']);

        $plan = ManagedServerPlan::query()
            ->where('active', true)
            ->where('provider', $connection->provider)
            ->findOrFail($data['managed_server_plan_id']);

        if (! in_array($data['region'], $plan->regions, true) || ! in_array($data['image'], $plan->images ?? [], true)) {
            throw ValidationException::withMessages(['region' => 'This region or image is unavailable.']);
        }

        $order = ManagedServerOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'provider_connection_id' => $connection->id,
            'managed_server_plan_id' => $plan->id,
            'name' => $data['name'],
            'region' => $data['region'],
            'image' => $data['image'],
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending_payment',
        ]);

        if ($this->billing->requiresPaymentGateway()) {
            $url = $this->gateway->checkoutManagedServer($tenant, $order, $successUrl, $cancelUrl);
            if (! $url) {
                throw new RuntimeException('Unable to start managed server payment. Configure Stripe billing.');
            }

            return ['redirect' => $url, 'order' => $order, 'server' => null];
        }

        if (! $this->billing->allowsInstantActivation()) {
            throw new RuntimeException('Online payment is required before a managed server can be created.');
        }

        $server = $this->fulfill($order);

        return ['redirect' => null, 'order' => $order->fresh(), 'server' => $server];
    }

    public function markPaidFromCheckoutSession(object $session): void
    {
        $order = ManagedServerOrder::query()
            ->whereKey($session->metadata->managed_server_order_id ?? null)
            ->where('tenant_id', $session->metadata->tenant_id ?? null)
            ->first();

        if (! $order || ! $order->isPendingPayment()) {
            return;
        }

        $order->update([
            'status' => 'paid',
            'paid_at' => now(),
            'stripe_checkout_session_id' => $session->id ?? $order->stripe_checkout_session_id,
        ]);

        $this->fulfill($order);
    }

    public function fulfill(ManagedServerOrder $order): \App\Models\Server
    {
        if ($order->server_id) {
            return $order->server()->firstOrFail();
        }

        $order->loadMissing(['providerConnection', 'managedPlan', 'user']);
        [$server, $operation] = $this->provisioning->createPending(
            $order->tenant_id,
            $order->user,
            $order->providerConnection,
            $order->managedPlan,
            $order->name,
            $order->region,
            $order->image,
            prepaid: true,
        );

        $order->update([
            'server_id' => $server->id,
            'status' => 'provisioning',
        ]);

        $this->provisioning->dispatchCreate($operation);

        return $server;
    }
}
