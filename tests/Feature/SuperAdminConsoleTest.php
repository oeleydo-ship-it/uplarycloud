<?php

namespace Tests\Feature;

use App\Models\ManagedServerPlan;
use App\Models\Plan;
use App\Models\ProviderConnection;
use App\Models\Setting;
use App\Models\User;
use App\Models\Tenant;
use App\Services\Billing\PlanLimitService;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuperAdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmins_can_access_platform_console(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Platform Overview');
    }

    public function test_superadmin_can_persist_global_settings_and_encrypted_gateway_secrets(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'platform_name' => 'Uplary',
            'platform_url' => 'https://uplary.test',
            'support_email' => 'support@uplary.test',
            'default_timezone' => 'UTC',
            'default_currency' => 'USD',
            'registration_enabled' => 1,
            'managed_servers_enabled' => 1,
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.payments.update'), [
            'billing_driver' => 'stripe',
            'stripe_public_key' => 'pk_test',
            'stripe_secret' => 'sk_secret',
            'stripe_webhook_secret' => 'whsec_secret',
            'paypal_mode' => 'sandbox',
            'tax_percentage' => 5,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('settings', ['tenant_id' => null, 'group' => 'general', 'key' => 'platform_name', 'value' => 'Uplary']);
        $this->assertDatabaseHas('settings', ['tenant_id' => null, 'group' => 'general', 'key' => 'managed_servers_enabled', 'value' => '1']);
        $secret = Setting::whereNull('tenant_id')->where('group', 'payments')->where('key', 'stripe_secret')->firstOrFail();
        $this->assertTrue($secret->is_encrypted);
        $this->assertNotSame('sk_secret', $secret->value);
    }

    public function test_superadmin_can_create_and_update_subscription_plan(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $response = $this->actingAs($admin)->post(route('admin.plans.store'), [
            'name' => 'Scale',
            'slug' => 'scale',
            'monthly_price' => 99,
            'yearly_price' => 999,
            'currency' => 'USD',
            'servers' => 20,
            'team_members' => 50,
            'backup_storage_gb' => 1000,
            'features' => "Priority support\nSSO",
            'active' => 1,
        ]);
        $response->assertSessionHasNoErrors();
        $plan = Plan::where('slug', 'scale')->firstOrFail();
        $this->assertSame(9900, $plan->monthly_price);
        $this->assertSame(20.0, $plan->limit('servers'));
        $this->assertContains('SSO', $plan->features);

        $this->actingAs($admin)->put(route('admin.plans.update', $plan), [
            'name' => 'Scale',
            'slug' => 'scale',
            'monthly_price' => 99,
            'yearly_price' => 999,
            'currency' => 'USD',
            'servers' => 20,
            'applications' => 40,
            'team_members' => 50,
            'backup_storage_gb' => 1000,
            'features' => "Priority support\nSSO",
            'gate_backups' => 1,
            'gate_api_tokens' => 1,
            'gate_monitoring' => 1,
            'active' => 1,
        ])->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertTrue($plan->allowsFeature('backups'));
        $this->assertTrue($plan->allowsFeature('api_tokens'));
        $this->assertFalse($plan->allowsFeature('git_deploy'));
        $this->assertSame(40.0, $plan->limit('applications'));
        $this->assertNull($plan->limit('domains'));
    }

    public function test_superadmin_can_assign_a_plan_and_status_to_a_workspace(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $tenant = Tenant::create(['name' => 'Customer Workspace']);
        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'monthly_price' => 2900, 'yearly_price' => 29000,
            'currency' => 'USD', 'limits' => ['servers' => 8], 'gates' => ['backups' => true],
            'features' => [], 'active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.tenants.subscription.update', $tenant), [
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $subscription = $tenant->subscriptions()->latest()->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('superadmin', $subscription->metadata['source']);
        $this->assertSame('growth', app(PlanLimitService::class)->plan($tenant)->slug);
        $this->assertSame(8.0, app(PlanLimitService::class)->plan($tenant)->limit('servers'));

        $this->actingAs($admin)->put(route('admin.tenants.subscription.update', $tenant), [
            'plan_id' => $plan->id,
            'status' => 'past_due',
            'billing_cycle' => 'monthly',
        ])->assertSessionHasNoErrors();
        $this->assertSame('free', app(PlanLimitService::class)->plan($tenant)->slug);

        $this->actingAs($admin)->get(route('admin.tenants'))
            ->assertOk()->assertSee('Plan control')->assertSee('Growth');
    }

    public function test_superadmin_can_impersonate_a_customer_workspace_and_return_safely(): void
    {
        $admin = User::factory()->create(['name' => 'Support Admin', 'is_super_admin' => true]);
        $customer = User::factory()->create(['name' => 'Customer Owner', 'is_super_admin' => false, 'email_verified_at' => null]);
        $tenant = Tenant::create(['name' => 'Customer Workspace']);
        $tenant->users()->attach($customer, ['role' => 'owner', 'is_active' => true]);
        $plan = Plan::create([
            'name' => 'Support Pro', 'slug' => 'support-pro', 'monthly_price' => 2900, 'yearly_price' => 29000,
            'currency' => 'USD', 'limits' => ['servers' => 10], 'gates' => [], 'features' => [], 'active' => true,
        ]);
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'monthly']);

        $this->actingAs($admin)->get(route('admin.users'))
            ->assertOk()->assertSee('Support access')->assertSee('Support Pro plan');

        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer), ['tenant_id' => $tenant->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('impersonator_id', $admin->id)
            ->assertSessionHas('tenant_id', $tenant->id);
        $this->assertAuthenticatedAs($customer);

        $this->get(route('dashboard'))
            ->assertOk()->assertSee('Support session')->assertSee('Support Pro plan')->assertSee('Return to Platform Console');
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'action' => 'support.impersonation.started']);

        $this->post(route('impersonation.leave'))->assertRedirect(route('admin.users'));
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('impersonator_id'));
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'action' => 'support.impersonation.ended']);
    }

    public function test_impersonation_rejects_superadmins_and_foreign_workspaces(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $otherAdmin = User::factory()->create(['is_super_admin' => true]);
        $customer = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Foreign Workspace']);

        $this->actingAs($admin)->post(route('admin.users.impersonate', $otherAdmin), ['tenant_id' => $tenant->id])
            ->assertSessionHasErrors('user');
        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer), ['tenant_id' => $tenant->id])
            ->assertSessionHasErrors('tenant_id');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_connecting_provider_api_syncs_catalog_with_global_markup(): void
    {
        config()->set('infrastructure.managed_driver', 'fake');
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->get(route('admin.cloud'))
            ->assertOk()
            ->assertSee('Managed Cloud')
            ->assertSee('No plans published yet');

        $this->actingAs($admin)->post(route('admin.cloud.connections.store'), [
            'name' => 'Platform DigitalOcean',
            'provider' => 'digitalocean',
            'api_token' => 'secret',
            'active' => 1,
        ])->assertSessionHasNoErrors();

        $connection = ProviderConnection::where('platform_managed', true)->firstOrFail();
        $this->assertTrue($connection->active);
        $this->assertNotNull($connection->last_verified_at);

        $plans = ManagedServerPlan::where('provider', 'digitalocean')->where('active', true)->get();
        $this->assertGreaterThanOrEqual(15, $plans->count());
        $this->assertTrue($plans->contains('provider_plan_id', 's-8vcpu-16gb'));
        $this->assertTrue($plans->contains('provider_plan_id', 'c-2'));
        $this->assertTrue($plans->contains('provider_plan_id', 'g-2vcpu-8gb'));

        $basic = ManagedServerPlan::where('provider_plan_id', 's-1vcpu-1gb')->firstOrFail();
        $this->assertSame(600, $basic->monthly_cost);
        $this->assertSame(100, $basic->markup_percentage);
        $this->assertSame(1200, $basic->monthly_price);
        $this->assertContains('fra1', $basic->regions);
        $this->assertContains('ubuntu-24.04', $basic->images);

        $this->actingAs($admin)->get(route('admin.cloud'))
            ->assertOk()
            ->assertSee($basic->name)
            ->assertDontSee('No plans published yet');
    }

    public function test_apply_markup_recalculates_customer_prices_and_sync_endpoint_refreshes_plans(): void
    {
        config()->set('infrastructure.managed_driver', 'fake');
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->post(route('admin.cloud.connections.store'), [
            'name' => 'Platform DigitalOcean',
            'provider' => 'digitalocean',
            'api_token' => 'secret',
            'active' => 1,
        ])->assertSessionHasNoErrors();

        $plan = ManagedServerPlan::where('provider_plan_id', 's-1vcpu-1gb')->firstOrFail();
        $this->assertSame(1200, $plan->monthly_price);

        $manual = ManagedServerPlan::create([
            'provider' => 'hetzner',
            'provider_plan_id' => 'cx22-manual',
            'name' => 'Manual CX22',
            'cpu_cores' => 2,
            'memory_mb' => 4096,
            'disk_gb' => 40,
            'bandwidth_gb' => 1000,
            'monthly_cost' => 500,
            'markup_percentage' => 20,
            'monthly_price' => 600,
            'currency' => 'EUR',
            'regions' => ['fsn1'],
            'images' => ['ubuntu-24.04'],
            'active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.cloud.markup.update'), [
            'markup_percentage' => 50,
        ])->assertSessionHasNoErrors();

        $this->assertSame(50, (int) app(PlatformSettings::class)->get('cloud', 'global_markup_percentage'));
        $this->assertSame(900, $plan->fresh()->monthly_price); // 600 * 1.5
        $this->assertSame(750, $manual->fresh()->monthly_price); // 500 * 1.5
        $this->assertSame(50, $plan->fresh()->markup_percentage);

        $connection = ProviderConnection::where('platform_managed', true)->firstOrFail();
        $this->actingAs($admin)->post(route('admin.cloud.connections.sync', $connection))
            ->assertSessionHasNoErrors();

        $this->assertSame(900, ManagedServerPlan::where('provider_plan_id', 's-1vcpu-1gb')->firstOrFail()->monthly_price);
    }

    public function test_digitalocean_catalog_maps_sizes_and_images_from_api(): void
    {
        config()->set('infrastructure.managed_driver', 'live');
        Http::fake([
            'api.digitalocean.com/v2/account' => Http::response(['account' => ['email' => 'ops@uplary.test']], 200),
            'api.digitalocean.com/v2/images*' => Http::response([
                'images' => [
                    ['slug' => 'ubuntu-24-04-x64'],
                    ['slug' => 'ubuntu-22-04-x64'],
                    ['slug' => 'fedora-40-x64'],
                ],
                'links' => ['pages' => []],
            ], 200),
            'api.digitalocean.com/v2/sizes*' => Http::response([
                'sizes' => [
                    [
                        'slug' => 's-1vcpu-1gb',
                        'available' => true,
                        'vcpus' => 1,
                        'memory' => 1024,
                        'disk' => 25,
                        'transfer' => 1.0,
                        'price_monthly' => 6.0,
                        'regions' => ['fra1', 'nyc3'],
                    ],
                    [
                        'slug' => 's-2vcpu-4gb',
                        'available' => true,
                        'vcpus' => 2,
                        'memory' => 4096,
                        'disk' => 80,
                        'transfer' => 4.0,
                        'price_monthly' => 24.0,
                        'regions' => ['fra1', 'nyc3', 'ams3'],
                    ],
                    [
                        'slug' => 's-1vcpu-512mb-10gb',
                        'available' => true,
                        'vcpus' => 1,
                        'memory' => 512,
                        'disk' => 10,
                        'transfer' => 0.5,
                        'price_monthly' => 4.0,
                        'regions' => ['fra1'],
                    ],
                    [
                        'slug' => 'gpu-4000adax1-20gb',
                        'available' => true,
                        'vcpus' => 8,
                        'memory' => 32768,
                        'disk' => 500,
                        'transfer' => 10,
                        'price_monthly' => 500,
                        'regions' => ['nyc2'],
                    ],
                    [
                        'slug' => 's-unavailable',
                        'available' => false,
                        'vcpus' => 1,
                        'memory' => 1024,
                        'disk' => 25,
                        'transfer' => 1,
                        'price_monthly' => 6,
                        'regions' => ['fra1'],
                    ],
                ],
                'links' => ['pages' => []],
            ], 200),
        ]);

        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->post(route('admin.cloud.connections.store'), [
            'name' => 'Live DO',
            'provider' => 'digitalocean',
            'api_token' => 'do-token',
            'active' => 1,
        ])->assertSessionHasNoErrors();

        $plan = ManagedServerPlan::where('provider_plan_id', 's-1vcpu-1gb')->firstOrFail();
        $this->assertSame(600, $plan->monthly_cost);
        $this->assertSame(1200, $plan->monthly_price);
        $this->assertSame(['fra1', 'nyc3'], $plan->regions);
        $this->assertSame(['ubuntu-24.04', 'ubuntu-22.04'], $plan->images);
        $this->assertDatabaseHas('managed_server_plans', ['provider_plan_id' => 's-2vcpu-4gb', 'monthly_cost' => 2400]);
        $this->assertDatabaseMissing('managed_server_plans', ['provider_plan_id' => 's-1vcpu-512mb-10gb']);
        $this->assertDatabaseMissing('managed_server_plans', ['provider_plan_id' => 'gpu-4000adax1-20gb']);
        $this->assertDatabaseMissing('managed_server_plans', ['provider_plan_id' => 's-unavailable']);
    }
}
