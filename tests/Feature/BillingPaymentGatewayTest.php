<?php

namespace Tests\Feature;

use App\Models\ManagedServerOrder;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_plan_requires_payment_gateway_when_instant_activation_is_disabled(): void
    {
        config()->set('billing.allow_instant_activation', false);
        config()->set('billing.driver', 'fake');

        [$owner, $tenant] = $this->workspace();
        $pro = $this->plan('pro', 2900);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->from(route('billing.index'))
            ->post(route('billing.subscribe'), [
                'plan_id' => $pro->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors('billing');

        $this->assertDatabaseMissing('subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $pro->id,
            'status' => 'active',
        ]);
    }

    public function test_subscribe_surfaces_stripe_errors_instead_of_server_error(): void
    {
        app(PlatformSettings::class)->put('payments', [
            'billing_driver' => 'stripe',
            'stripe_secret' => 'sk_test_invalid',
        ]);

        [$owner, $tenant] = $this->workspace();
        $pro = $this->plan('pro', 2900, stripeMonthly: 'price_test_monthly', stripeYearly: 'price_test_yearly');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->from('https://upentra.test/billing')
            ->post('https://upentra.test/billing/subscribe', [
                'plan_id' => $pro->id,
                'billing_cycle' => 'monthly',
            ], [
                'HTTP_HOST' => 'upentra.test',
                'HTTPS' => 'on',
            ])
            ->assertRedirect('https://upentra.test/billing')
            ->assertSessionHasErrors('billing');
    }

    public function test_free_plan_can_be_selected_without_payment_gateway(): void
    {
        config()->set('billing.allow_instant_activation', false);
        config()->set('billing.driver', 'fake');

        [$owner, $tenant] = $this->workspace();
        $free = $this->plan('free', 0);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('billing.subscribe'), [
                'plan_id' => $free->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $free->id,
            'status' => 'active',
        ]);
    }

    public function test_paid_subscription_requires_stripe_subscription_when_stripe_billing_is_enabled(): void
    {
        app(PlatformSettings::class)->put('payments', [
            'billing_driver' => 'stripe',
            'stripe_secret' => 'sk_test_example',
        ]);

        [$owner, $tenant] = $this->workspace();
        $pro = $this->plan('pro', 2900);
        $tenant->subscriptions()->create([
            'plan_id' => $pro->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'metadata' => ['gateway' => 'fake'],
        ]);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('managed.index'))
            ->assertPaymentRequired();
    }

    public function test_managed_server_order_requires_payment_when_stripe_billing_is_enabled(): void
    {
        $this->enableManagedServers();
        app(PlatformSettings::class)->put('payments', [
            'billing_driver' => 'stripe',
            'stripe_secret' => 'sk_test_example',
        ]);
        config()->set('billing.allow_instant_activation', false);

        [$owner, $tenant] = $this->managedWorkspace();
        $connection = $this->platformConnection();
        $managedPlan = $this->managedPlan();

        $tenant->subscriptions()->delete();
        $pro = $this->plan('pro', 2900, ['managed_servers' => 5, 'servers' => 10]);
        $pro->update(['gates' => ['managed_servers' => true]]);
        $tenant->subscriptions()->create([
            'plan_id' => $pro->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'stripe_subscription_id' => 'sub_test_123',
        ]);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('managed.servers.store'), [
                'name' => 'Paid Managed Server',
                'provider_connection_id' => $connection->id,
                'managed_server_plan_id' => $managedPlan->id,
                'region' => 'fra1',
                'image' => 'ubuntu-24.04',
            ])
            ->assertSessionHasErrors('payment');

        $this->assertDatabaseMissing('servers', ['tenant_id' => $tenant->id, 'name' => 'Paid Managed Server']);
        $this->assertDatabaseHas('managed_server_orders', [
            'tenant_id' => $tenant->id,
            'name' => 'Paid Managed Server',
            'status' => 'pending_payment',
        ]);
    }

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);

        return [$owner, $tenant];
    }

    private function managedWorkspace(): array
    {
        return $this->workspace();
    }

    private function plan(string $slug, int $price, array $limits = [], ?string $stripeMonthly = null, ?string $stripeYearly = null): Plan
    {
        return Plan::create([
            'name' => ucfirst($slug),
            'slug' => $slug.'-'.fake()->unique()->numerify('###'),
            'description' => 'Test plan',
            'monthly_price' => $price,
            'yearly_price' => $price * 10,
            'currency' => 'USD',
            'stripe_monthly_price_id' => $stripeMonthly,
            'stripe_yearly_price_id' => $stripeYearly,
            'limits' => $limits ?: ['servers' => 10, 'managed_servers' => 5, 'team_members' => 5],
            'gates' => ['managed_servers' => true],
            'features' => ['Test'],
            'active' => true,
        ]);
    }

    private function enableManagedServers(): void
    {
        app(PlatformSettings::class)->put('general', ['managed_servers_enabled' => true]);
    }

    private function platformConnection(): \App\Models\ProviderConnection
    {
        return \App\Models\ProviderConnection::create([
            'tenant_id' => null,
            'name' => 'Platform DO',
            'provider' => 'digitalocean',
            'api_token' => 'platform-token',
            'active' => true,
            'platform_managed' => true,
            'last_verified_at' => now(),
        ]);
    }

    private function managedPlan(): \App\Models\ManagedServerPlan
    {
        return \App\Models\ManagedServerPlan::create([
            'provider' => 'digitalocean',
            'provider_plan_id' => 's-1vcpu-2gb',
            'name' => 'Starter Managed',
            'cpu_cores' => 1,
            'memory_mb' => 2048,
            'disk_gb' => 50,
            'bandwidth_gb' => 2000,
            'monthly_cost' => 600,
            'monthly_price' => 900,
            'currency' => 'USD',
            'regions' => ['fra1'],
            'images' => ['ubuntu-24.04'],
            'active' => true,
        ]);
    }
}
