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
use App\Services\Infrastructure\InfrastructureBillingService;
use App\Services\Infrastructure\ManagedInfrastructureService;
use App\Services\Infrastructure\Providers\DigitalOceanAdapter;
use App\Services\Infrastructure\Providers\HetznerCloudAdapter;
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
        [$owner, $tenant] = $this->workspace();
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->post(route('managed.connections.store'), [
            'name' => 'Production DO', 'provider' => 'digitalocean', 'api_token' => 'super-secret-token',
        ])->assertRedirect()->assertSessionHas('success');

        $connection = ProviderConnection::firstOrFail();
        $this->assertSame('super-secret-token', $connection->api_token);
        $this->assertDatabaseMissing('provider_connections', ['api_token' => 'super-secret-token']);
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('managed.connections.verify', $connection))->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($connection->fresh()->last_verified_at);
    }

    public function test_managed_console_is_role_and_tenant_scoped(): void
    {
        [$owner, $tenant] = $this->workspace();
        $this->managedPlan();
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->get(route('managed.index'))
            ->assertOk()->assertSee('Managed infrastructure');
        $viewer = User::factory()->create();
        $tenant->users()->attach($viewer, ['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])->get(route('managed.index'))->assertForbidden();

        [$otherOwner, $otherTenant] = $this->workspace();
        $connection = $this->connection($tenant);
        $this->actingAs($otherOwner)->withSession(['tenant_id' => $otherTenant->id])
            ->post(route('managed.connections.verify', $connection))->assertNotFound();
    }

    public function test_add_server_page_exposes_verified_digitalocean_and_hetzner_api_accounts(): void
    {
        [$owner, $tenant] = $this->workspace();
        $digitalOcean = $this->managedPlan();
        $hetzner = ManagedServerPlan::create(['provider' => 'hetzner', 'provider_plan_id' => 'cx22',
            'name' => 'Shared CX22', 'cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40,
            'bandwidth_gb' => 20000, 'monthly_cost' => 450, 'monthly_price' => 850, 'currency' => 'USD',
            'regions' => ['fsn1', 'nbg1'], 'images' => ['ubuntu-24.04'], 'active' => true]);
        ProviderConnection::create(['tenant_id' => null, 'name' => 'Production DigitalOcean',
            'provider' => 'digitalocean', 'api_token' => 'do-token', 'active' => true, 'platform_managed' => true, 'last_verified_at' => now()]);
        ProviderConnection::create(['tenant_id' => null, 'name' => 'Production Hetzner',
            'provider' => 'hetzner', 'api_token' => 'hz-token', 'active' => true, 'platform_managed' => true, 'last_verified_at' => now()]);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->get(route('servers.create'))
            ->assertOk()
            ->assertSee('Provision with cloud API')
            ->assertSee('Production DigitalOcean')
            ->assertSee('Production Hetzner')
            ->assertSee($digitalOcean->name)
            ->assertSee($hetzner->name);
    }

    public function test_digitalocean_adapter_sends_a_real_droplet_create_request(): void
    {
        Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(['droplet' => [
            'id' => 12345, 'status' => 'new', 'networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.15']]],
        ]], 202)]);
        [$owner, $tenant] = $this->workspace();
        $connection = $this->connection($tenant);
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
            'provider' => 'hetzner', 'api_token' => 'hetzner-token', 'active' => true, 'last_verified_at' => now()]);
        $plan = ManagedServerPlan::create(['provider' => 'hetzner', 'provider_plan_id' => 'cx22', 'name' => 'CX22',
            'cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40, 'bandwidth_gb' => 20000,
            'monthly_cost' => 450, 'monthly_price' => 850, 'currency' => 'USD', 'regions' => ['fsn1'],
            'images' => ['ubuntu-24.04'], 'active' => true]);
        $server = Server::create(['tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id,
            'managed_server_plan_id' => $plan->id, 'name' => 'Hetzner Production', 'provider' => 'hetzner',
            'provider_region' => 'fsn1', 'provider_image' => 'ubuntu-24.04', 'ip_address' => '0.0.0.0',
            'operating_system' => 'ubuntu-24.04', 'server_type' => 'managed', 'status' => 'pending',
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
        Queue::fake();
        [$owner, $tenant] = $this->workspace();
        $plan = $this->managedPlan();
        $connection = $this->connection($tenant);

        $response = $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->post(route('managed.servers.store'), [
            'name' => 'Managed API', 'provider_connection_id' => $connection->id,
            'managed_server_plan_id' => $plan->id, 'region' => 'fra1', 'image' => 'ubuntu-24.04',
        ])->assertRedirect()->assertSessionHas('success');

        $server = Server::where('name', 'Managed API')->firstOrFail();
        $response->assertRedirect(route('servers.provisioning',$server));
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
        $operation = $this->operation($server, $owner, 'create');
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

    private function workspace(int $managedLimit = 5): array
    {
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro-'.fake()->unique()->numerify('###'), 'description' => 'Test',
            'monthly_price' => 2900, 'yearly_price' => 29000, 'currency' => 'USD',
            'limits' => ['servers' => 10, 'managed_servers' => $managedLimit, 'team_members' => 10], 'features' => [], 'active' => true]);
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'monthly']);
        return [$owner, $tenant];
    }

    private function connection(Tenant $tenant): ProviderConnection
    {
        return ProviderConnection::create(['tenant_id' => null, 'name' => 'Cloud '.fake()->unique()->word(),
            'provider' => 'digitalocean', 'api_token' => 'test-token', 'active' => true, 'platform_managed' => true, 'last_verified_at' => now()]);
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
        $connection = ProviderConnection::firstWhere('tenant_id', $tenant->id) ?? $this->connection($tenant);
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
