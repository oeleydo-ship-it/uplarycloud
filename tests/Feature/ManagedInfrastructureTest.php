<?php

namespace Tests\Feature;

use App\Jobs\CreateManagedServerJob;
use App\Jobs\ManagedServerActionJob;
use App\Models\InfrastructureOperation;
use App\Models\ManagedServerPlan;
use App\Models\Plan;
use App\Models\ProviderConnection;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Infrastructure\CloudProviderFactory;
use App\Services\Infrastructure\InfrastructureBillingService;
use App\Services\Infrastructure\ManagedInfrastructureService;
use App\Services\Infrastructure\Providers\DigitalOceanAdapter;
use App\Services\Infrastructure\Providers\FakeCloudProviderAdapter;
use App\Services\Infrastructure\Providers\HetznerCloudAdapter;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManagedInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.managed_driver', 'fake');
    }

    public function test_owner_can_save_encrypted_credentials_and_verify_connection(): void
    {
        $this->fakeDigitalOceanAccount();
        [$owner, $tenant] = $this->workspace();
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->post(route('managed.connections.store'), [
            'name' => 'Production DO', 'provider' => 'digitalocean', 'api_token' => 'super-secret-token',
        ])->assertRedirect()->assertSessionHas('success');

        $connection = ProviderConnection::firstOrFail();
        $this->assertSame('super-secret-token', $connection->api_token);
        $this->assertSame($tenant->id, $connection->tenant_id);
        $this->assertFalse($connection->platform_managed);
        $this->assertDatabaseMissing('provider_connections', ['api_token' => 'super-secret-token']);
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('managed.connections.verify', $connection))->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($connection->fresh()->last_verified_at);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.digitalocean.com/v2/account'
            && $request->hasHeader('Authorization', 'Bearer super-secret-token'));
    }

    public function test_tenant_cannot_verify_platform_managed_connection(): void
    {
        [$owner, $tenant] = $this->workspace();
        $platform = $this->platformConnection();

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('managed.connections.verify', $platform))
            ->assertNotFound();
    }

    public function test_owner_can_delete_own_unused_provider_credentials_but_not_an_attached_connection(): void
    {
        [$owner, $tenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('managed.connections.destroy', $connection))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertModelMissing($connection);

        $attached = $this->tenantConnection($tenant);
        Server::create(['tenant_id' => $tenant->id, 'provider_connection_id' => $attached->id, 'name' => 'Attached', 'provider' => 'digitalocean', 'ip_address' => '203.0.113.77', 'operating_system' => 'ubuntu-24.04', 'status' => 'offline', 'authentication_method' => 'ssh_key']);
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('managed.connections.destroy', $attached))
            ->assertSessionHasErrors('provider');
        $this->assertModelExists($attached);
    }

    public function test_managed_console_is_role_and_tenant_scoped(): void
    {
        $this->enableManagedServers();
        [$owner, $tenant] = $this->workspace();
        $this->managedPlan();
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->get(route('managed.index'))
            ->assertOk()->assertSee('Managed servers');
        $viewer = User::factory()->create();
        $tenant->users()->attach($viewer, ['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])->get(route('managed.index'))->assertForbidden();

        [$otherOwner, $otherTenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant);
        $this->actingAs($otherOwner)->withSession(['tenant_id' => $otherTenant->id])
            ->post(route('managed.connections.verify', $connection))->assertNotFound();
    }

    public function test_managed_servers_require_an_active_paid_subscription(): void
    {
        $this->enableManagedServers();
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $plan = Plan::create([
            'name' => 'Free', 'slug' => 'free-'.fake()->unique()->numerify('###'), 'description' => 'Test',
            'monthly_price' => 0, 'yearly_price' => 0, 'currency' => 'USD',
            'limits' => ['servers' => 10, 'managed_servers' => 5],
            'gates' => ['managed_servers' => true], 'features' => [], 'active' => true,
        ]);
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'monthly']);
        $managedPlan = $this->managedPlan();
        $connection = $this->platformConnection();

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('managed.index'))
            ->assertPaymentRequired()
            ->assertSee('Payment required')
            ->assertSee('Choose a paid plan');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('managed.servers.store'), [
                'name' => 'Blocked Managed Server',
                'provider_connection_id' => $connection->id,
                'managed_server_plan_id' => $managedPlan->id,
                'region' => 'fra1',
                'image' => 'ubuntu-24.04',
            ])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors('payment');

        $this->assertDatabaseMissing('servers', ['tenant_id' => $tenant->id, 'name' => 'Blocked Managed Server']);
    }

    public function test_managed_option_hidden_until_superadmin_enables_it(): void
    {
        [$owner, $tenant] = $this->workspace();
        $this->platformConnection();
        $this->managedPlan();

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Add custom own server')
            ->assertDontSee('Add managed server');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('servers.create.managed'))
            ->assertNotFound();

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('managed.index'))
            ->assertNotFound();

        $this->enableManagedServers();

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Add managed server');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('servers.create.managed'))
            ->assertOk()
            ->assertSee('Add managed server')
            ->assertDontSee('My API account');
    }

    public function test_custom_create_page_uses_tenant_cloud_api_not_platform_tokens(): void
    {
        [$owner, $tenant] = $this->workspace();
        $this->managedPlan();
        $this->platformConnection('Platform DigitalOcean');
        $tenantConnection = $this->tenantConnection($tenant, 'My DigitalOcean');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->get(route('servers.create'))
            ->assertOk()
            ->assertSee('Provision with my Cloud API')
            ->assertSee('My DigitalOcean')
            ->assertSee(route('managed.connections.catalog', $tenantConnection), false)
            ->assertDontSee('Platform DigitalOcean')
            ->assertDontSee('name="managed_server_plan_id"', false);
    }

    public function test_tenant_cloud_catalog_loads_sizes_from_the_connection_token(): void
    {
        $this->fakeDigitalOceanCatalog();
        [$owner, $tenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant, 'test - DigitalOcean');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->getJson(route('managed.connections.catalog', $connection))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('provider', 'digitalocean')
            ->assertJsonFragment(['provider_plan_id' => 's-1vcpu-1gb'])
            ->assertJsonFragment(['provider_plan_id' => 's-2vcpu-4gb']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.digitalocean.com/v2/sizes')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_tenant_cloud_catalog_surfaces_an_error_when_the_token_is_rejected(): void
    {
        Http::fake(['api.digitalocean.com/*' => Http::response(['id' => 'unauthorized', 'message' => 'Unable to authenticate you.'], 401)]);
        [$owner, $tenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->getJson(route('managed.connections.catalog', $connection))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['error' => 'DigitalOcean rejected this API token. Re-verify the connection under My Cloud API.']);
    }

    public function test_tenant_cloud_catalog_surfaces_an_error_when_no_sizes_are_returned(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/images*' => Http::response(['images' => [['slug' => 'ubuntu-24-04-x64']], 'links' => ['pages' => []]], 200),
            'api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [[
                'slug' => 'gpu-4000adax1-20gb', 'available' => true, 'vcpus' => 8, 'memory' => 32768, 'disk' => 500,
                'transfer' => 10, 'price_monthly' => 500, 'regions' => ['nyc1'],
            ]], 'links' => ['pages' => []]], 200),
        ]);
        [$owner, $tenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->getJson(route('managed.connections.catalog', $connection))
            ->assertStatus(422)
            ->assertJsonPath('plans', [])
            ->assertSee('No provisionable sizes were returned', false);
    }

    public function test_tenant_cloud_api_uses_live_adapter_even_when_managed_driver_is_fake(): void
    {
        config()->set('infrastructure.managed_driver', 'fake');
        [$owner, $tenant] = $this->workspace();
        $tenantConnection = $this->tenantConnection($tenant);
        $platform = $this->platformConnection();

        $this->assertInstanceOf(DigitalOceanAdapter::class, app(CloudProviderFactory::class)->make($tenantConnection));
        $this->assertInstanceOf(FakeCloudProviderAdapter::class, app(CloudProviderFactory::class)->make($platform));
    }

    public function test_tenant_cloud_provision_uses_own_credentials_and_skips_managed_billing(): void
    {
        $this->fakeDigitalOceanCatalog();
        Queue::fake();
        [$owner, $tenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant);
        $platform = $this->platformConnection('Platform DO');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->post(route('servers.cloud.store'), [
            'name' => 'BYO Cloud', 'provider_connection_id' => $connection->id,
            'provider_plan_id' => 's-1vcpu-1gb', 'region' => 'fra1', 'image' => 'ubuntu-24.04',
        ])->assertRedirect()->assertSessionHas('success');

        $server = Server::where('name', 'BYO Cloud')->firstOrFail();
        $this->assertSame('byos', $server->server_type);
        $this->assertSame(ManagedInfrastructureService::PROVISIONING_SSH_USER, $server->ssh_username);
        $this->assertSame('ssh_key', $server->authentication_method->value);
        $this->assertSame($connection->id, $server->provider_connection_id);
        $this->assertNotSame($platform->id, $server->provider_connection_id);
        $this->assertNull($server->managed_server_plan_id);
        $this->assertSame('s-1vcpu-1gb', $server->infrastructureOperations()->first()->parameters['plan']);
        Queue::assertPushedOn('infrastructure', CreateManagedServerJob::class);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->post(route('servers.cloud.store'), [
            'name' => 'Steal Platform', 'provider_connection_id' => $platform->id,
            'provider_plan_id' => 's-1vcpu-1gb', 'region' => 'fra1', 'image' => 'ubuntu-24.04',
        ])->assertNotFound();
    }

    public function test_digitalocean_adapter_sends_a_real_droplet_create_request(): void
    {
        Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(['droplet' => [
            'id' => 12345, 'status' => 'new', 'networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.15']]],
        ]], 202)]);
        [$owner, $tenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant);
        $plan = $this->managedPlan();
        $server = $this->managedServer($tenant);
        $server->setRelation('providerConnection', $connection);

        $result = app(DigitalOceanAdapter::class)->create($server, $plan, [
            'region' => 'fra1', 'image' => 'ubuntu-24.04', 'user_data' => '#cloud-config',
        ]);

        $this->assertSame('12345', $result['resource_id']);
        $this->assertSame('203.0.113.15', $result['ip_address']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.digitalocean.com/v2/droplets'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['region'] === 'fra1' && $request['size'] === 's-1vcpu-1gb'
            && $request['image'] === 'ubuntu-24-04-x64' && $request['user_data'] === '#cloud-config');
    }

    public function test_hetzner_adapter_sends_a_real_cloud_server_create_request(): void
    {
        Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['server' => [
            'id' => 67890, 'status' => 'initializing', 'public_net' => ['ipv4' => ['ip' => '203.0.113.25']],
        ]], 201)]);
        [$owner, $tenant] = $this->workspace();
        $connection = ProviderConnection::create(['tenant_id' => $tenant->id, 'name' => 'Hetzner API',
            'provider' => 'hetzner', 'api_token' => 'hetzner-token', 'active' => true, 'platform_managed' => false, 'last_verified_at' => now()]);
        $plan = ManagedServerPlan::create(['provider' => 'hetzner', 'provider_plan_id' => 'cx22', 'name' => 'CX22',
            'cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40, 'bandwidth_gb' => 20000,
            'monthly_cost' => 450, 'monthly_price' => 850, 'currency' => 'USD', 'regions' => ['fsn1'],
            'images' => ['ubuntu-24.04'], 'active' => true]);
        $server = Server::create(['tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id,
            'managed_server_plan_id' => $plan->id, 'name' => 'Hetzner Production', 'provider' => 'hetzner',
            'provider_region' => 'fsn1', 'provider_image' => 'ubuntu-24.04', 'ip_address' => '0.0.0.0',
            'operating_system' => 'ubuntu-24.04', 'server_type' => 'byos', 'status' => 'pending',
            'authentication_method' => 'ssh_key', 'cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40]);
        $server->setRelation('providerConnection', $connection);

        $result = app(HetznerCloudAdapter::class)->create($server, $plan, [
            'region' => 'fsn1', 'image' => 'ubuntu-24.04', 'user_data' => '#cloud-config',
        ]);

        $this->assertSame('67890', $result['resource_id']);
        $this->assertSame('203.0.113.25', $result['ip_address']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
            && $request->hasHeader('Authorization', 'Bearer hetzner-token')
            && $request['location'] === 'fsn1' && $request['server_type'] === 'cx22'
            && $request['image'] === 'ubuntu-24.04' && $request['user_data'] === '#cloud-config');
    }

    public function test_create_request_builds_server_steps_and_queues_cloud_job(): void
    {
        $this->enableManagedServers();
        Queue::fake();
        [$owner, $tenant] = $this->workspace();
        $plan = $this->managedPlan();
        $connection = $this->platformConnection();

        $response = $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->post(route('managed.servers.store'), [
            'name' => 'Managed API', 'provider_connection_id' => $connection->id,
            'managed_server_plan_id' => $plan->id, 'region' => 'fra1', 'image' => 'ubuntu-24.04',
        ])->assertRedirect()->assertSessionHas('success');

        $server = Server::where('name', 'Managed API')->firstOrFail();
        $response->assertRedirect(route('servers.provisioning', $server));
        $this->assertSame('managed', $server->server_type);
        $this->assertSame('pending', $server->status->value);
        $this->assertCount(7, $server->provisioningSteps);
        $this->assertDatabaseHas('infrastructure_operations', ['server_id' => $server->id, 'action' => 'create', 'status' => 'pending']);
        Queue::assertPushedOn('infrastructure', CreateManagedServerJob::class);
    }

    public function test_fake_provider_provisions_and_accrues_compute_charge_once(): void
    {
        [$owner, $tenant] = $this->workspace();
        $server = $this->managedServer($tenant);
        $operation = $this->operation($server, $owner, 'create', ['billing' => true]);
        $service = app(ManagedInfrastructureService::class);

        $service->create($operation);
        $server->refresh();
        $this->assertNotNull($server->provider_resource_id);
        $this->assertNotSame('0.0.0.0', $server->ip_address);
        $this->assertSame('provisioning', $server->status->value);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertDatabaseHas('infrastructure_charges', ['tenant_id' => $tenant->id, 'server_id' => $server->id, 'charge_type' => 'managed_server', 'total' => 900]);

        app(InfrastructureBillingService::class)->accrue($server->load('managedPlan'));
        $this->assertSame(1, $server->infrastructureCharges()->where('charge_type', 'managed_server')->count());
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->get(route('billing.index'))
            ->assertOk()->assertSee('Managed infrastructure charges');
    }

    public function test_byos_cloud_provision_does_not_accrue_platform_charges(): void
    {
        Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(['droplet' => [
            'id' => 555, 'status' => 'new', 'networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.40']]],
        ]], 202)]);
        [$owner, $tenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant);
        $server = Server::create([
            'tenant_id' => $tenant->id,
            'provider_connection_id' => $connection->id,
            'managed_server_plan_id' => null,
            'name' => 'BYO Billing Check',
            'provider' => 'digitalocean',
            'provider_region' => 'fra1',
            'provider_image' => 'ubuntu-24.04',
            'ip_address' => '0.0.0.0',
            'operating_system' => 'ubuntu-24.04',
            'server_type' => 'byos',
            'status' => 'pending',
            'authentication_method' => 'ssh_key',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 25,
        ]);
        $operation = $this->operation($server, $owner, 'create', ['billing' => false, 'public_key' => 'ssh-rsa test', 'plan' => 's-1vcpu-1gb']);

        app(ManagedInfrastructureService::class)->create($operation);

        $this->assertDatabaseMissing('infrastructure_charges', ['server_id' => $server->id]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.digitalocean.com/v2/droplets'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['size'] === 's-1vcpu-1gb'
            && str_contains((string) $request['user_data'], 'disable_root: false')
            && str_contains((string) $request['user_data'], "chpasswd:\n  expire: false")
            && str_contains((string) $request['user_data'], 'ssh_authorized_keys:')
            && str_contains((string) $request['user_data'], 'name: root'));
    }

    public function test_lifecycle_actions_restart_resize_rebuild_and_destroy(): void
    {
        [$owner, $tenant] = $this->workspace();
        $server = $this->managedServer($tenant, true);
        $larger = $this->managedPlan('s-2vcpu-2gb', 1700, 2, 2048);
        $service = app(ManagedInfrastructureService::class);

        foreach ([
            ['restart', []],
            ['resize', ['managed_server_plan_id' => $larger->id]],
            ['rebuild', ['image' => 'ubuntu-22.04']],
            ['destroy', []],
        ] as [$action, $parameters]) {
            $operation = $this->operation($server, $owner, $action, $parameters);
            $service->perform($operation);
            $this->assertSame('completed', $operation->fresh()->status);
            $server->refresh();
        }

        $this->assertSame($larger->id, $server->managed_server_plan_id);
        $this->assertSame('ubuntu-22.04', $server->provider_image);
        $this->assertSame('offline', $server->status->value);
        $this->assertDatabaseHas('infrastructure_charges', ['server_id' => $server->id, 'charge_type' => 'resize_adjustment']);
    }

    public function test_controller_queues_action_and_managed_plan_limit_is_enforced(): void
    {
        $this->enableManagedServers();
        Queue::fake();
        [$owner, $tenant] = $this->workspace(1);
        $server = $this->managedServer($tenant, true);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->post(route('managed.servers.action', $server), [
            'action' => 'restart',
        ])->assertRedirect()->assertSessionHas('success');
        Queue::assertPushedOn('infrastructure', ManagedServerActionJob::class);

        $connection = $server->providerConnection;
        $plan = $server->managedPlan;
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->post(route('managed.servers.store'), [
            'name' => 'Over Limit', 'provider_connection_id' => $connection->id,
            'managed_server_plan_id' => $plan->id, 'region' => 'fra1', 'image' => 'ubuntu-24.04',
        ])->assertSessionHasErrors('managed_servers');
        $this->assertDatabaseMissing('servers', ['tenant_id' => $tenant->id, 'name' => 'Over Limit']);
    }

    public function test_create_job_dispatches_provisioning_after_instance_is_ready(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\ProvisionServerJob::class]);
        [$owner, $tenant] = $this->workspace();
        $server = $this->managedServer($tenant);
        $operation = $this->operation($server, $owner, 'create', ['billing' => true, 'public_key' => 'ssh-rsa test']);

        app()->call([new CreateManagedServerJob($operation->id), 'handle']);

        $server->refresh();
        $this->assertNotSame('0.0.0.0', $server->ip_address);
        $this->assertNotNull($server->provider_resource_id);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ProvisionServerJob::class, fn ($job) => $job->server->is($server) && $job->delay === null);
    }

    public function test_pending_cloud_create_disables_manual_retry_and_does_not_queue_a_duplicate_job(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        [$owner, $tenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant);
        $server = Server::create([
            'tenant_id' => $tenant->id,
            'provider_connection_id' => $connection->id,
            'name' => 'Pending Cloud',
            'provider' => 'digitalocean',
            'ip_address' => '0.0.0.0',
            'operating_system' => 'ubuntu-24.04',
            'server_type' => 'byos',
            'status' => 'pending',
            'authentication_method' => 'ssh_key',
            'ssh_username' => 'root',
        ]);
        $server->provisioningSteps()->create(['key' => 'connect', 'label' => 'Connecting', 'position' => 1]);
        $operation = $this->operation($server, $owner, 'create', ['plan' => 's-1vcpu-1gb']);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('servers.provisioning.retry', $server))
            ->assertStatus(409);

        \Illuminate\Support\Facades\Bus::assertNotDispatched(CreateManagedServerJob::class);
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\ProvisionServerJob::class);

        config()->set('queue.default', 'sync');
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->getJson(route('servers.provisioning.status', $server))
            ->assertOk()
            ->assertJsonPath('needs_attention', false);
    }

    public function test_failed_cloud_create_can_be_safely_requeued_before_provisioning(): void
    {
        Queue::fake();
        [$owner, $tenant] = $this->workspace();
        $server = $this->managedServer($tenant);
        $server->update([
            'ip_address' => '0.0.0.0',
            'provider_resource_id' => null,
            'status' => 'failed',
            'failure_reason' => 'Managed infrastructure operation failed.',
        ]);
        $operation = $this->operation($server, $owner, 'create', ['plan' => 's-1vcpu-2gb']);
        $operation->update([
            'status' => 'failed',
            'last_error' => 'Temporary provider connection failure.',
            'completed_at' => now(),
        ]);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('servers.provisioning.retry', $server))
            ->assertRedirect(route('servers.provisioning', $server))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('infrastructure_operations', [
            'id' => $operation->id,
            'status' => 'pending',
            'last_error' => null,
        ]);
        $server->refresh();
        $this->assertSame('pending', $server->status->value);
        $this->assertNull($server->failure_reason);
        Queue::assertPushedOn('infrastructure', CreateManagedServerJob::class);
        Queue::assertNotPushed(\App\Jobs\ProvisionServerJob::class);
    }

    public function test_cloud_create_retry_polls_existing_provider_resource_instead_of_creating_another(): void
    {
        Http::fake(['api.digitalocean.com/v2/droplets/592366992' => Http::response(['droplet' => [
            'id' => 592366992,
            'status' => 'active',
            'networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.91']]],
        ]], 200)]);
        [$owner, $tenant] = $this->workspace();
        $connection = $this->tenantConnection($tenant);
        $server = Server::create([
            'tenant_id' => $tenant->id,
            'provider_connection_id' => $connection->id,
            'name' => 'Existing Pending Droplet',
            'provider' => 'digitalocean',
            'provider_resource_id' => '592366992',
            'provider_region' => 'fra1',
            'provider_image' => 'ubuntu-24.04',
            'ip_address' => '0.0.0.0',
            'operating_system' => 'ubuntu-24.04',
            'server_type' => 'byos',
            'status' => 'pending',
            'authentication_method' => 'ssh_key',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 25,
        ]);
        $operation = $this->operation($server, $owner, 'create', [
            'billing' => false,
            'plan' => 's-1vcpu-1gb',
        ]);

        app(ManagedInfrastructureService::class)->create($operation);

        $this->assertSame('592366992', $server->fresh()->provider_resource_id);
        $this->assertSame('203.0.113.91', $server->fresh()->ip_address);
        $this->assertSame('completed', $operation->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.digitalocean.com/v2/droplets/592366992');
    }

    private function enableManagedServers(): void
    {
        app(PlatformSettings::class)->put('general', ['managed_servers_enabled' => true]);
    }

    private function workspace(int $managedLimit = 5): array
    {
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro-'.fake()->unique()->numerify('###'), 'description' => 'Test',
            'monthly_price' => 2900, 'yearly_price' => 29000, 'currency' => 'USD',
            'limits' => ['servers' => 10, 'managed_servers' => $managedLimit, 'team_members' => 10],
            'gates' => ['managed_servers' => true, 'cloud_api' => true], 'features' => [], 'active' => true]);
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'monthly']);

        return [$owner, $tenant];
    }

    private function fakeDigitalOceanAccount(): void
    {
        Http::fake(['api.digitalocean.com/v2/account' => Http::response(['account' => ['email' => 'owner@example.com']], 200)]);
    }

    private function fakeDigitalOceanCatalog(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/images*' => Http::response([
                'images' => [
                    ['slug' => 'ubuntu-24-04-x64'],
                    ['slug' => 'ubuntu-22-04-x64'],
                    ['slug' => 'debian-12-x64'],
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
                        'regions' => ['fra1', 'ams3'],
                    ],
                ],
                'links' => ['pages' => []],
            ], 200),
        ]);
    }

    private function platformConnection(string $name = 'Cloud Platform'): ProviderConnection
    {
        return ProviderConnection::create([
            'tenant_id' => null,
            'name' => $name.' '.fake()->unique()->numerify('###'),
            'provider' => 'digitalocean',
            'api_token' => 'platform-token',
            'active' => true,
            'platform_managed' => true,
            'last_verified_at' => now(),
        ]);
    }

    private function tenantConnection(Tenant $tenant, string $name = 'Tenant Cloud'): ProviderConnection
    {
        return ProviderConnection::create([
            'tenant_id' => $tenant->id,
            'name' => $name.' '.fake()->unique()->numerify('###'),
            'provider' => 'digitalocean',
            'api_token' => 'test-token',
            'active' => true,
            'platform_managed' => false,
            'last_verified_at' => now(),
        ]);
    }

    private function managedPlan(string $providerId = 's-1vcpu-1gb', int $price = 900, int $cpu = 1, int $memory = 1024): ManagedServerPlan
    {
        return ManagedServerPlan::create(['provider' => 'digitalocean', 'provider_plan_id' => $providerId,
            'name' => 'Basic '.$memory, 'cpu_cores' => $cpu, 'memory_mb' => $memory, 'disk_gb' => 25,
            'bandwidth_gb' => 1000, 'monthly_cost' => 600, 'monthly_price' => $price, 'currency' => 'USD',
            'regions' => ['fra1', 'nyc3'], 'images' => ['ubuntu-24.04', 'ubuntu-22.04'], 'active' => true]);
    }

    private function managedServer(Tenant $tenant, bool $online = false): Server
    {
        $plan = ManagedServerPlan::first() ?? $this->managedPlan();
        $connection = ProviderConnection::query()->where('platform_managed', true)->first() ?? $this->platformConnection();

        return Server::create(['tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id,
            'managed_server_plan_id' => $plan->id, 'name' => 'Managed '.fake()->unique()->word(),
            'provider' => 'digitalocean', 'provider_resource_id' => $online ? 'cloud-123' : null,
            'provider_region' => 'fra1', 'provider_image' => 'ubuntu-24.04', 'ip_address' => $online ? '198.51.100.12' : '0.0.0.0',
            'operating_system' => 'ubuntu-24.04', 'server_type' => 'managed', 'status' => $online ? 'online' : 'pending',
            'authentication_method' => 'ssh_key', 'cpu_cores' => $plan->cpu_cores, 'memory_mb' => $plan->memory_mb, 'disk_gb' => $plan->disk_gb]);
    }

    private function operation(Server $server, User $owner, string $action, array $parameters = []): InfrastructureOperation
    {
        return InfrastructureOperation::create(['tenant_id' => $server->tenant_id, 'server_id' => $server->id,
            'requested_by' => $owner->id, 'action' => $action, 'status' => 'pending', 'parameters' => $parameters]);
    }
}
