<?php

namespace Tests\Feature;

use App\Jobs\DockerResourceActionJob;
use App\Models\DockerContainer;
use App\Models\DockerImage;
use App\Models\DockerNetwork;
use App\Models\DockerVolume;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Docker\ComposeSecurityValidator;
use App\Services\Docker\DockerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DockerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_docker_pages_only_show_active_tenant_resources(): void
    {
        [$user,$tenant,$server]=$this->setupOwner(); [,,$foreignServer]=$this->setupOwner();
        DockerContainer::create($this->containerData($tenant,$server,'Own Container'));
        DockerContainer::create($this->containerData($foreignServer->tenant,$foreignServer,'Foreign Container'));
        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->get('/containers')->assertOk()->assertSee('Own Container')->assertDontSee('Foreign Container');
    }

    public function test_containers_index_loads_when_server_is_soft_deleted(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        DockerContainer::create($this->containerData($tenant, $server, 'Orphaned Worker'));
        $serverName = $server->name;
        $server->delete();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('containers.index'))
            ->assertOk()
            ->assertSee('Orphaned Worker')
            ->assertSee($serverName)
            ->assertDontSee('Server removed');
    }

    public function test_volumes_and_images_index_load_when_server_is_soft_deleted(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        DockerVolume::create([
            'tenant_id' => $tenant->id,
            'server_id' => $server->id,
            'docker_name' => 'orphaned-data',
            'name' => 'Orphaned Volume',
        ]);
        DockerImage::create([
            'tenant_id' => $tenant->id,
            'server_id' => $server->id,
            'repository' => 'orphaned/app',
            'tag' => '1',
            'used_by_count' => 1,
        ]);
        DockerNetwork::create([
            'tenant_id' => $tenant->id,
            'server_id' => $server->id,
            'name' => 'orphaned-net',
            'driver' => 'bridge',
            'scope' => 'local',
        ]);
        $serverName = $server->name;
        $server->delete();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('volumes.index'))
            ->assertOk()
            ->assertSee('Orphaned Volume')
            ->assertSee($serverName)
            ->assertDontSee('Server removed');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('images.index', ['show_unused' => 1]))
            ->assertOk()
            ->assertSee('orphaned/app')
            ->assertSee($serverName)
            ->assertDontSee('Server removed');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('networks.index'))
            ->assertOk()
            ->assertSee('orphaned-net')
            ->assertSee($serverName)
            ->assertDontSee('Server removed');
    }

    public function test_container_restart_runs_immediately_and_updates_state(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create($this->containerData($tenant, $server, 'Worker'));

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('containers.action', $container), ['action' => 'restart'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Worker restarted.');

        $container->refresh();
        $this->assertSame('running', $container->status->value);
        $this->assertSame(1, $container->restart_count);
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'docker.container.restart',
            'subject_id' => $container->id,
        ]);
    }

    public function test_fake_driver_start_stop_and_restart_update_status(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create($this->containerData($tenant, $server, 'App'));
        $docker = app(DockerService::class);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('containers.action', $container), ['action' => 'stop'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('stopped', $container->refresh()->status->value);

        $this->post(route('containers.action', $container), ['action' => 'start'])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame('running', $container->refresh()->status->value);

        $this->post(route('containers.action', $container), ['action' => 'restart'])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame('running', $container->refresh()->status->value);
        $this->assertSame(1, $container->restart_count);

        $docker->container($container, 'stop');
        $this->assertSame('stopped', $container->refresh()->status->value);
        $docker->container($container, 'start');
        $this->assertSame('running', $container->refresh()->status->value);
    }

    public function test_viewer_cannot_operate_containers_and_foreign_tenant_is_isolated(): void
    {
        [$owner, $tenant, $server] = $this->setupOwner();
        [$foreignUser, $foreignTenant, $foreignServer] = $this->setupOwner();
        $viewer = User::factory()->create();
        $tenant->users()->attach($viewer, ['role' => 'viewer']);

        $container = DockerContainer::create($this->containerData($tenant, $server, 'Owned'));
        $foreign = DockerContainer::create($this->containerData($foreignTenant, $foreignServer, 'Foreign'));

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])
            ->post(route('containers.action', $container), ['action' => 'stop'])
            ->assertForbidden();

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])
            ->post(route('containers.sync'))
            ->assertForbidden();

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('containers.action', $foreign), ['action' => 'stop'])
            ->assertNotFound();

        $this->actingAs($foreignUser)->withSession(['tenant_id' => $foreignTenant->id])
            ->post(route('containers.action', $container), ['action' => 'restart'])
            ->assertNotFound();
    }

    public function test_container_action_blocked_when_server_is_trashed(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create($this->containerData($tenant, $server, 'Orphan'));
        $server->delete();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('containers.action', $container), ['action' => 'stop'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('running', $container->refresh()->status->value);
    }

    public function test_sync_refreshes_inventory_for_owner(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create(array_merge($this->containerData($tenant, $server, 'Synced'), [
            'status' => 'running',
            'health' => 'unhealthy',
        ]));

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('containers.sync'), ['server' => $server->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('unhealthy', $container->refresh()->status->value);
    }

    public function test_inspect_action_refreshes_single_container(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create(array_merge($this->containerData($tenant, $server, 'Inspected'), [
            'status' => 'running',
            'health' => 'unhealthy',
        ]));

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('containers.action', $container), ['action' => 'inspect'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('unhealthy', $container->refresh()->status->value);
    }

    public function test_null_memory_limit_does_not_render_hardcoded_gigabyte(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        DockerContainer::create(array_merge($this->containerData($tenant, $server, 'Unlimited Mem'), [
            'memory_usage_mb' => 508,
            'memory_limit_mb' => null,
        ]));

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('containers.index'))
            ->assertOk()
            ->assertSee('508 MB / Unlimited')
            ->assertDontSee('1.0 GB');
    }

    public function test_apply_inspect_persists_memory_limit_from_host_config(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create(array_merge($this->containerData($tenant, $server, 'Limited'), [
            'memory_usage_mb' => 128,
            'memory_limit_mb' => null,
        ]));

        app(DockerService::class)->applyInspectPayload($container, [
            'Id' => 'abcdef1234567890',
            'State' => ['Status' => 'running', 'RestartCount' => 0],
            'Config' => ['Image' => 'nginx:alpine', 'Labels' => []],
            'HostConfig' => ['Memory' => 536870912], // 512 MiB
            'NetworkSettings' => ['Ports' => []],
        ]);

        $container->refresh();
        $this->assertSame(512, $container->memory_limit_mb);
        $this->assertSame('128 MB / 512 MB', $container->memoryLabel());
    }

    public function test_apply_inspect_clears_stale_memory_limit_when_unlimited(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create(array_merge($this->containerData($tenant, $server, 'Was Limited'), [
            'memory_usage_mb' => 318,
            'memory_limit_mb' => 1024,
        ]));

        app(DockerService::class)->applyInspectPayload($container, [
            'Id' => 'fedcba0987654321',
            'State' => ['Status' => 'running', 'RestartCount' => 0],
            'Config' => ['Image' => 'nginx:alpine', 'Labels' => []],
            'HostConfig' => ['Memory' => 0],
            'NetworkSettings' => ['Ports' => []],
        ]);

        $container->refresh();
        $this->assertNull($container->memory_limit_mb);
        $this->assertSame('318 MB / Unlimited', $container->memoryLabel());
        $this->assertSame(0, $container->memoryPercent());
        $this->assertStringNotContainsString('1.0 GB', $container->memoryLabel());
    }

    public function test_expose_only_ports_are_shown_as_internal_not_host_ports(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create(array_merge($this->containerData($tenant, $server, 'Queue Sidecar'), [
            'ports' => [['private' => 8000]],
        ]));

        $this->assertSame('Internal', $container->formattedPorts());

        app(DockerService::class)->applyInspectPayload($container, [
            'Id' => 'abcdef1234567890',
            'State' => ['Status' => 'running', 'RestartCount' => 0],
            'Config' => ['Image' => 'platform/app:latest', 'Labels' => []],
            'HostConfig' => ['Memory' => 0],
            // Docker marks Dockerfile EXPOSE as null bindings — not published to the host.
            'NetworkSettings' => ['Ports' => ['8000/tcp' => null]],
        ]);

        $container->refresh();
        $this->assertSame([], $container->ports);
        $this->assertSame('Internal', $container->formattedPorts());

        app(DockerService::class)->applyInspectPayload($container, [
            'Id' => 'abcdef1234567890',
            'State' => ['Status' => 'running', 'RestartCount' => 0],
            'Config' => ['Image' => 'platform/app:latest', 'Labels' => []],
            'HostConfig' => ['Memory' => 0],
            'NetworkSettings' => ['Ports' => [
                '8000/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '8012']],
            ]],
        ]);

        $container->refresh();
        $this->assertSame([['private' => 8000, 'public' => 8012]], $container->ports);
        $this->assertSame('8012:8000', $container->formattedPorts());
    }

    public function test_refresh_stats_does_not_persist_host_ram_as_memory_limit(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create(array_merge($this->containerData($tenant, $server, 'Stats Host Ram'), [
            'memory_usage_mb' => 100,
            'memory_limit_mb' => null,
            'status' => 'running',
        ]));

        $executor = \Mockery::mock(\App\Contracts\Infrastructure\ServerExecutorInterface::class);
        $executor->shouldReceive('execute')
            ->once()
            ->andReturn('12.50%|508MiB / 1GiB');

        $service = new DockerService($executor);
        $method = new \ReflectionMethod(DockerService::class, 'refreshStats');
        $method->setAccessible(true);
        $method->invoke($service, $container);

        $container->refresh();
        $this->assertSame(508, $container->memory_usage_mb);
        $this->assertNull($container->memory_limit_mb);
        $this->assertSame('508 MB / Unlimited', $container->memoryLabel());
        $this->assertStringNotContainsString('1.0 GB', $container->memoryLabel());
    }

    public function test_containers_index_shows_start_for_stopped_and_stop_for_running(): void
    {
        [$user, $tenant, $server] = $this->setupOwner();
        DockerContainer::create(array_merge($this->containerData($tenant, $server, 'Running Box'), [
            'status' => 'running',
        ]));
        DockerContainer::create(array_merge($this->containerData($tenant, $server, 'Stopped Box'), [
            'status' => 'stopped',
        ]));

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('containers.index'))
            ->assertOk()
            ->assertSee('aria-label="Stop Running Box"', false)
            ->assertSee('aria-label="Restart Running Box"', false)
            ->assertDontSee('aria-label="Start Running Box"', false)
            ->assertSee('aria-label="Start Stopped Box"', false)
            ->assertSee('aria-label="Restart Stopped Box"', false)
            ->assertDontSee('aria-label="Stop Stopped Box"', false);
    }

    public function test_restart_runs_docker_restart_over_ssh_and_refreshes_status(): void
    {
        config(['infrastructure.driver' => 'ssh']);
        [$user, $tenant, $server] = $this->setupOwner();
        $container = DockerContainer::create(array_merge($this->containerData($tenant, $server, 'cloudpress-ge6ed-db'), [
            'docker_id' => 'a1b2c3d4e5f6',
            'status' => 'running',
            'restart_count' => 0,
        ]));

        $executor = new class extends \App\Services\Infrastructure\FakeServerExecutor
        {
            /** @var list<string> */
            public array $commands = [];

            public function execute(\App\Models\Server $server, string $command, ?int $timeoutSeconds = null): string
            {
                $this->commands[] = $command;

                if (str_starts_with($command, 'docker restart ')) {
                    return '';
                }

                if (str_contains($command, 'docker inspect')) {
                    return json_encode([
                        'Id' => 'a1b2c3d4e5f6abcdef',
                        'State' => [
                            'Status' => 'running',
                            'RestartCount' => 1,
                            'StartedAt' => now()->toIso8601String(),
                            'FinishedAt' => '0001-01-01T00:00:00Z',
                            'Health' => ['Status' => 'healthy'],
                        ],
                        'Config' => ['Image' => 'postgres:16', 'Labels' => []],
                        'HostConfig' => ['Memory' => 0],
                        'NetworkSettings' => ['Ports' => []],
                    ]);
                }

                if (str_contains($command, 'docker stats')) {
                    return '2.10%|128MiB / 1GiB';
                }

                return parent::execute($server, $command, $timeoutSeconds);
            }
        };
        $this->app->instance(\App\Contracts\Infrastructure\ServerExecutorInterface::class, $executor);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('containers.action', $container), ['action' => 'restart'])
            ->assertRedirect()
            ->assertSessionHas('success', 'cloudpress-ge6ed-db restarted.');

        $restart = collect($executor->commands)->first(fn (string $command) => str_starts_with($command, 'docker restart '));
        $this->assertNotNull($restart);
        $this->assertSame("docker restart 'a1b2c3d4e5f6'", $restart);
        $this->assertTrue(collect($executor->commands)->contains(fn (string $command) => str_contains($command, 'docker inspect')));

        $container->refresh();
        $this->assertSame('running', $container->status->value);
        $this->assertSame(1, $container->restart_count);
        $this->assertSame('healthy', $container->health);
    }

    public function test_attached_volume_and_used_image_cannot_be_removed(): void
    {
        [$user,$tenant,$server]=$this->setupOwner();$container=DockerContainer::create($this->containerData($tenant,$server,'Database'));
        $volume=DockerVolume::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'docker_name'=>'db-data','name'=>'DB Data']);$container->volumes()->attach($volume,['mount_path'=>'/data']);
        $image=DockerImage::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'repository'=>'postgres','tag'=>'16','used_by_count'=>1]);
        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->delete(route('volumes.destroy',$volume),['confirmation'=>'DELETE'])->assertSessionHasErrors('volume');
        $this->post(route('images.action',$image),['action'=>'remove'])->assertSessionHasErrors('image');
        $this->assertDatabaseHas('docker_volumes',['id'=>$volume->id,'deleted_at'=>null]);$this->assertDatabaseHas('docker_images',['id'=>$image->id,'deleted_at'=>null]);
    }

    public function test_image_inventory_links_applications_containers_and_volumes(): void
    {
        [$user,$tenant,$server]=$this->setupOwner();
        $category=\App\Models\ApplicationCategory::create(['name'=>'Databases','slug'=>'databases']);
        $application=\App\Models\Application::create(['category_id'=>$category->id,'name'=>'PostgreSQL','slug'=>'postgresql','description'=>'Database','docker_image'=>'postgres','default_tag'=>'16','active'=>true]);
        $deployment=\App\Models\ApplicationDeployment::create(['tenant_id'=>$tenant->id,'application_id'=>$application->id,'server_id'=>$server->id,'created_by'=>$user->id,'name'=>'Customer Database','deployment_type'=>'marketplace','docker_image'=>'postgres','docker_tag'=>'16','restart_policy'=>'unless-stopped']);
        $container=DockerContainer::create(array_merge($this->containerData($tenant,$server,'Database'),['application_deployment_id'=>$deployment->id,'image'=>'postgres:16']));
        $volume=DockerVolume::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'docker_name'=>'database-data','name'=>'Database Data','size_bytes'=>1073741824]);
        $container->volumes()->attach($volume,['mount_path'=>'/var/lib/postgresql/data']);
        $image=DockerImage::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'repository'=>'postgres','tag'=>'16','size_bytes'=>400000000]);

        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->get(route('images.index',['show_unused'=>1,'selected'=>$image->uuid]))
            ->assertOk()->assertSee('Customer Database')->assertSee('Database')->assertSee('1 volume')->assertSee('postgres:16');
    }

    public function test_owner_can_pull_and_cleanup_unused_images(): void
    {
        Bus::fake();[$user,$tenant,$server]=$this->setupOwner();
        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->post(route('images.pull'),['server_id'=>$server->id,'repository'=>'redis','tag'=>'7-alpine'])->assertSessionHasNoErrors();
        $image=DockerImage::where('repository','redis')->firstOrFail();
        Bus::assertDispatched(DockerResourceActionJob::class,fn($job)=>$job->type==='image'&&$job->id===$image->id&&$job->action==='pull');

        Bus::fake();
        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->post(route('images.cleanup'))->assertSessionHasNoErrors();
        Bus::assertDispatched(DockerResourceActionJob::class,fn($job)=>$job->type==='image'&&$job->id===$image->id&&$job->action==='remove');
    }

    public function test_compose_security_blocks_dangerous_capabilities(): void
    {
        foreach (["services:\n  app:\n    image: test\n    privileged: true","services:\n  db:\n    image: mysql\n    ports: [\"3306:3306\"]","services:\n  app:\n    image: test\n    volumes: [/var/run/docker.sock:/var/run/docker.sock]"] as $compose) {
            try { app(ComposeSecurityValidator::class)->validate($compose);$this->fail('Dangerous Compose content was accepted.'); } catch (ValidationException) { $this->assertTrue(true); }
        }
        app(ComposeSecurityValidator::class)->validate("services:\n  web:\n    image: nginx\nnetworks:\n  internal:\n    internal: true");$this->assertTrue(true);
    }

    private function setupOwner(): array { $u=User::factory()->create();$t=Tenant::create(['name'=>fake()->unique()->company()]);$t->users()->attach($u,['role'=>'owner']);$s=Server::create(['tenant_id'=>$t->id,'name'=>'Docker Host','provider'=>'custom','ip_address'=>fake()->unique()->ipv4(),'operating_system'=>'ubuntu-24.04','status'=>'online','authentication_method'=>'ssh_key']);return[$u,$t,$s]; }
    private function containerData(Tenant $t,Server $s,string $name): array{return['tenant_id'=>$t->id,'server_id'=>$s->id,'name'=>$name,'image'=>'nginx:alpine','status'=>'running','docker_id'=>substr(hash('sha256',$name),0,12)];}
}
