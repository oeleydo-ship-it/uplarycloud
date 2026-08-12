<?php

namespace Tests\Feature;

use App\Jobs\CollectOperationsMetricsJob;
use App\Jobs\ProvisionServerJob;
use App\Models\ApplicationDeployment;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ServerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_server_and_credentials_are_encrypted(): void
    {
        Bus::fake(); [$user, $tenant] = $this->member('owner');
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/servers/create')->assertOk();
        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post('/servers', $this->payload());
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $server = Server::firstOrFail();
        $response->assertRedirect(route('servers.provisioning', $server));
        $this->assertSame($tenant->id, $server->tenant_id);
        $raw = \DB::table('server_credentials')->where('server_id', $server->id)->value('private_key');
        $this->assertNotSame('secret-private-key', $raw);
        $this->assertSame('secret-private-key', Crypt::decryptString($raw));
        $this->assertCount(7, $server->provisioningSteps);
        $this->get(route('servers.show', $server))->assertRedirect(route('servers.provisioning', $server));
        $this->get(route('servers.provisioning', $server))->assertOk()->assertSee('Provisioning Production')->assertDontSee('Deploy application');
        Bus::assertDispatched(ProvisionServerJob::class, fn ($job) => $job->server->is($server));
    }

    public function test_server_from_another_tenant_is_not_visible(): void
    {
        [$user, $tenant] = $this->member('owner'); [, $foreign] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), ['tenant_id' => $foreign->id, 'name' => 'Foreign']));
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get(route('servers.show', $server))->assertNotFound();
    }

    public function test_viewer_cannot_create_a_server(): void
    {
        [$user, $tenant] = $this->member('viewer');
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post('/servers', $this->payload())->assertForbidden();
    }

    public function test_fake_provisioning_completes_all_steps(): void
    {
        [$user, $tenant] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), ['tenant_id' => $tenant->id]));
        foreach (['connect', 'system', 'docker', 'configure', 'proxy', 'monitoring', 'verify'] as $position => $key) $server->provisioningSteps()->create(['key' => $key, 'label' => ucfirst($key), 'position' => $position + 1]);
        app()->call([new ProvisionServerJob($server), 'handle']);
        $this->assertSame('online', $server->refresh()->status->value);
        $this->assertSame(7, $server->provisioningSteps()->where('status', 'completed')->count());
        $this->assertTrue($server->isFullyProvisioned());
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $tenant->id, 'action' => 'server.provisioned']);
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $tenant->id, 'action' => 'server.provisioning.step']);
        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->get(route('servers.show',$server))->assertOk()->assertSee('System information');
    }

    public function test_store_server_with_install_docker_dispatches_provisioning_job_and_records_flags(): void
    {
        Bus::fake();
        [$user, $tenant] = $this->member('owner');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post('/servers', $this->payload([
            'install_docker' => '1',
            'install_proxy' => '0',
            'install_monitoring' => '0',
        ]))->assertRedirect();

        $server = Server::firstOrFail();
        $this->assertTrue($server->install_docker);
        $this->assertFalse($server->install_proxy);
        $this->assertFalse($server->install_monitoring);
        Bus::assertDispatched(ProvisionServerJob::class, fn ($job) => $job->server->is($server));
    }

    public function test_fake_driver_refuses_public_ip_provisioning(): void
    {
        [$user, $tenant] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), [
            'tenant_id' => $tenant->id,
            'ip_address' => '142.93.127.29',
        ]));
        foreach (['connect', 'system', 'docker'] as $position => $key) {
            $server->provisioningSteps()->create(['key' => $key, 'label' => ucfirst($key), 'position' => $position + 1]);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('INFRASTRUCTURE_DRIVER=ssh');

        app()->call([new ProvisionServerJob($server), 'handle']);
    }

    public function test_online_server_missing_remote_services_can_be_reprovisioned(): void
    {
        Bus::fake();
        config(['infrastructure.driver' => 'ssh']);
        [$user, $tenant] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), [
            'tenant_id' => $tenant->id,
            'status' => 'online',
            'docker_version' => '28.3.3',
            'docker_compose_version' => '2.38.2',
            'provisioned_at' => now(),
        ]));
        foreach (['connect', 'system', 'docker', 'configure', 'proxy', 'monitoring', 'verify'] as $position => $key) {
            $server->provisioningSteps()->create([
                'key' => $key,
                'label' => ucfirst($key),
                'position' => $position + 1,
                'status' => 'completed',
            ]);
        }

        $this->mock(\App\Services\Servers\ServerProvisionVerifier::class, function ($mock): void {
            $mock->shouldReceive('failures')->andReturn(['Docker Engine is not installed on the host.']);
            $mock->shouldReceive('allowsSimulatedProvisioning')->andReturn(false);
            $mock->shouldReceive('assertProvisioned')->andReturnNull();
        });

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('servers.provisioning.retry', $server))
            ->assertRedirect(route('servers.provisioning', $server));

        $server->refresh();
        $this->assertSame('pending', $server->status->value);
        $this->assertNull($server->provisioned_at);
        $this->assertNull($server->docker_version);
        Bus::assertDispatched(ProvisionServerJob::class, fn ($job) => $job->server->is($server) && $job->force === true);
    }

    public function test_failed_server_remains_locked_and_can_be_retried(): void
    {
        Bus::fake(); [$user,$tenant]=$this->member('owner');
        $server=Server::create(array_merge($this->serverAttributes(),['tenant_id'=>$tenant->id,'status'=>'failed','failure_reason'=>'SSH authentication failed.']));
        $server->provisioningSteps()->create(['key'=>'connect','label'=>'Connecting','position'=>1,'status'=>'failed','message'=>'SSH authentication failed.']);
        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->get(route('servers.show',$server))->assertRedirect(route('servers.provisioning', $server));
        $this->get(route('servers.provisioning',$server))->assertOk()->assertSee('Provisioning needs attention')->assertDontSee('Deploy application');
        $this->post(route('servers.provisioning.retry',$server))->assertRedirect(route('servers.provisioning',$server));
        $this->assertSame('pending',$server->refresh()->status->value); Bus::assertDispatched(ProvisionServerJob::class);
    }

    public function test_reference_inventory_filters_by_tag_status_and_sort(): void
    {
        [$user,$tenant]=$this->member('owner');
        Server::create(array_merge($this->serverAttributes(),['tenant_id'=>$tenant->id,'name'=>'Production Server','status'=>'online','tags'=>['production']]));
        Server::create(array_merge($this->serverAttributes(),['tenant_id'=>$tenant->id,'name'=>'Staging Server','ip_address'=>'203.0.113.41','status'=>'offline','tags'=>['staging']]));
        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->get(route('servers.index',['status'=>'online','tag'=>'production','sort'=>'name']))
            ->assertOk()->assertSee('Total Containers')->assertSee('Total Volumes')->assertSee('Production Server')->assertDontSee('Staging Server');
    }

    public function test_inventory_actions_link_to_details_manage_and_destroy(): void
    {
        [$user, $tenant] = $this->member('owner');
        $pending = Server::create(array_merge($this->serverAttributes(), ['tenant_id' => $tenant->id, 'name' => 'Pending Box', 'status' => 'pending']));
        $online = Server::create(array_merge($this->serverAttributes(), ['tenant_id' => $tenant->id, 'name' => 'Online Box', 'ip_address' => '203.0.113.50', 'status' => 'online', 'provisioned_at' => now()]));
        $this->seedProvisioningSteps($online, completed: true);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get(route('servers.index'))
            ->assertOk()
            ->assertSee('View server details')
            ->assertSee('Destroy server')
            ->assertSee(route('servers.details', $pending), false)
            ->assertSee(route('servers.provisioning', $pending), false)
            ->assertSee(route('servers.show', $online), false)
            ->assertSee(route('servers.destroy', $pending), false);

        $this->get(route('servers.details', $pending))->assertRedirect(route('servers.provisioning', $pending));
        $this->delete(route('servers.destroy', $pending))->assertRedirect(route('servers.index'));
        $this->assertSoftDeleted($pending);
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $tenant->id, 'action' => 'server.deleted']);
    }

    public function test_destroy_is_blocked_when_server_has_application_deployments(): void
    {
        [$user, $tenant] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), ['tenant_id' => $tenant->id, 'name' => 'Busy Box', 'status' => 'online']));
        ApplicationDeployment::create([
            'tenant_id' => $tenant->id,
            'server_id' => $server->id,
            'created_by' => $user->id,
            'name' => 'Busy App',
            'deployment_type' => 'custom',
            'docker_image' => 'nginx',
            'docker_tag' => 'latest',
            'restart_policy' => 'unless-stopped',
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Remove applications first')
            ->assertDontSee('Destroy server');

        $this->delete(route('servers.destroy', $server))
            ->assertRedirect(route('servers.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('servers', ['id' => $server->id, 'deleted_at' => null]);
    }

    public function test_destroy_is_allowed_when_server_has_no_application_deployments(): void
    {
        [$user, $tenant] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), ['tenant_id' => $tenant->id, 'name' => 'Empty Box', 'status' => 'online']));

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('servers.destroy', $server))
            ->assertRedirect(route('servers.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted($server);
    }

    public function test_viewer_cannot_destroy_a_server(): void
    {
        [, $tenant] = $this->member('owner');
        $viewer = User::factory()->create();
        $tenant->users()->attach($viewer, ['role' => 'viewer']);
        $server = Server::create(array_merge($this->serverAttributes(), ['tenant_id' => $tenant->id, 'name' => 'Locked Box']));

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])->get(route('servers.index'))
            ->assertOk()->assertSee('View server details')->assertDontSee('Destroy server');
        $this->delete(route('servers.destroy', $server))->assertForbidden();
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'deleted_at' => null]);
    }

    public function test_details_page_shows_real_configuration_without_fake_metrics(): void
    {
        [$user, $tenant] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), [
            'tenant_id' => $tenant->id,
            'name' => 'Newproductionserver',
            'ip_address' => '203.0.113.55',
            'status' => 'online',
            'cpu_cores' => null,
            'memory_mb' => null,
            'disk_gb' => null,
            'docker_version' => '28.3.3',
            'docker_compose_version' => '2.38.2',
            'install_docker' => true,
            'install_proxy' => false,
            'install_monitoring' => false,
            'proxy_status' => 'not_installed',
            'provisioned_at' => now()->subDays(2)->subHours(5),
            'last_seen_at' => now()->subMinutes(3),
            'tags' => ['production'],
        ]));
        $this->seedProvisioningSteps($server, completed: true);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('servers.details', $server))
            ->assertOk()
            ->assertSee('Newproductionserver')
            ->assertSee('203.0.113.55', false)
            ->assertSee('28.3.3', false)
            ->assertSee('2.38.2', false)
            ->assertSee('root@203.0.113.55:22', false)
            ->assertDontSee('{{ $server->ip_address }}', false)
            ->assertDontSee('>32%<', false)
            ->assertDontSee('>48%<', false)
            ->assertDontSee('>61%<', false)
            ->assertDontSee('24d 7h', false)
            ->assertDontSee('Architecture', false)
            ->assertDontSee('4 vCPU', false)
            ->assertDontSee('8 GB installed', false)
            ->assertDontSee('160 GB total', false)
            ->assertSee('Detecting…', false)
            ->assertSee('No metrics yet', false)
            ->assertSee('Skipped', false)
            ->assertSee('Docker Engine', false)
            ->assertSee('Running', false)
            ->assertSee('2d 5h', false)
            ->assertSee('production', false)
            ->assertSee(route('logs.index', ['server_id' => $server->id]), false)
            ->assertSee(route('containers.index', ['server' => $server->id]), false)
            ->assertSee(route('applications.installed', ['server' => $server->id]), false)
            ->assertSee(route('domains.index', ['server' => $server->id]), false)
            ->assertSee(route('volumes.index', ['server' => $server->id]), false)
            ->assertSee(route('monitoring.index', ['server' => $server->id]), false)
            ->assertSee(route('images.index', ['server' => $server->id]), false)
            ->assertSee(route('applications.index', ['server' => $server->id]), false)
            ->assertSee('#settings', false)
            ->assertSee('id="settings"', false);
    }

    public function test_details_page_renders_collected_metric_values(): void
    {
        [$user, $tenant] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), [
            'tenant_id' => $tenant->id,
            'name' => 'Metric Box',
            'ip_address' => '203.0.113.56',
            'status' => 'online',
            'cpu_cores' => 2,
            'memory_mb' => 4096,
            'disk_gb' => 80,
            'docker_version' => '27.1.0',
            'docker_compose_version' => '2.29.0',
            'install_docker' => true,
            'install_proxy' => true,
            'install_monitoring' => true,
            'proxy_status' => 'running',
            'proxy_version' => 'traefik:v3.1',
            'proxy_installed_at' => now()->subDay(),
            'provisioned_at' => now()->subHour(),
            'last_seen_at' => now(),
        ]));
        $this->seedProvisioningSteps($server, completed: true);

        ServerMetric::create([
            'server_id' => $server->id,
            'cpu_percent' => 41.6,
            'memory_percent' => 57.2,
            'disk_percent' => 33.0,
            'load_average' => 0.94,
            'network_in_bytes' => 1000,
            'network_out_bytes' => 2000,
            'recorded_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('servers.details', $server))
            ->assertOk()
            ->assertSee('>42%<', false)
            ->assertSee('>57%<', false)
            ->assertSee('>33%<', false)
            ->assertSee('2 vCPU', false)
            ->assertSee('4 GB installed', false)
            ->assertSee('80 GB total', false)
            ->assertSee('load 0.94', false)
            ->assertDontSee('No metrics yet', false)
            ->assertDontSee('>32%<', false)
            ->assertDontSee('4 vCPU', false)
            ->assertDontSee('8 GB installed', false)
            ->assertSee('traefik:v3.1', false)
            ->assertSee('Running', false);
    }

    public function test_refresh_queues_metric_collection_for_online_server(): void
    {
        Bus::fake();
        [$user, $tenant] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), [
            'tenant_id' => $tenant->id,
            'name' => 'Refresh Box',
            'status' => 'online',
        ]));

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('servers.refresh', $server))
            ->assertRedirect(route('servers.details', $server))
            ->assertSessionHas('success')
            ->assertSessionHas('metrics_refresh', true);

        Bus::assertDispatched(CollectOperationsMetricsJob::class, fn ($job) => $job->serverId === $server->id);
    }

    public function test_viewer_cannot_refresh_server_metrics(): void
    {
        Bus::fake();
        [, $tenant] = $this->member('owner');
        $viewer = User::factory()->create();
        $tenant->users()->attach($viewer, ['role' => 'viewer']);
        $server = Server::create(array_merge($this->serverAttributes(), [
            'tenant_id' => $tenant->id,
            'name' => 'Locked Refresh',
            'status' => 'online',
        ]));

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])
            ->post(route('servers.refresh', $server))
            ->assertForbidden();

        Bus::assertNotDispatched(CollectOperationsMetricsJob::class);
    }

    private function member(string $role): array
    {
        $user = User::factory()->create(); $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($user, ['role' => $role]); return [$user, $tenant];
    }

    public function test_owner_can_add_server_with_platform_key_authorization(): void
    {
        Bus::fake();
        [$user, $tenant] = $this->member('owner');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post('/servers', array_merge(
            $this->serverAttributes(),
            [
                'authorization_method' => 'platform_key',
                'authentication_method' => 'ssh_key',
                'install_docker' => '1',
                'install_proxy' => '1',
                'install_monitoring' => '1',
            ]
        ));

        $response->assertSessionHasNoErrors();
        $server = Server::firstOrFail();
        $response->assertRedirect(route('servers.provisioning', $server));

        $stored = $server->credential()->firstOrFail();
        $this->assertNotEmpty($stored->private_key);
        $this->assertStringContainsString('PRIVATE KEY', $stored->private_key);

        $platformPrivate = app(\App\Services\Servers\ControlPlaneKeyService::class)->privateKeyForTenant($tenant);
        $this->assertSame($platformPrivate, $stored->private_key);

        $this->get(route('servers.create'))
            ->assertOk()
            ->assertSee('Install platform key', false)
            ->assertSee('Provide SSH credentials', false)
            ->assertSee(app(\App\Services\Servers\ControlPlaneKeyService::class)->publicKeyForTenant($tenant), false);

        Bus::assertDispatched(ProvisionServerJob::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge($this->serverAttributes(), [
            'authorization_method' => 'credentials',
            'private_key' => 'secret-private-key',
            'install_docker' => '1',
            'install_proxy' => '1',
            'install_monitoring' => '1',
        ], $overrides);
    }

    private function serverAttributes(): array
    {
        return ['name' => 'Production', 'provider' => 'custom', 'ip_address' => '203.0.113.40', 'location' => 'Dubai', 'operating_system' => 'ubuntu-24.04', 'ssh_port' => 22, 'ssh_username' => 'root', 'authentication_method' => 'ssh_key', 'connection_timeout' => 15];
    }

    private function seedProvisioningSteps(Server $server, bool $completed = false): void
    {
        foreach (['connect', 'system', 'docker', 'configure', 'proxy', 'monitoring', 'verify'] as $position => $key) {
            $server->provisioningSteps()->create([
                'key' => $key,
                'label' => ucfirst($key),
                'position' => $position + 1,
                'status' => $completed ? 'completed' : 'pending',
            ]);
        }
    }
}
