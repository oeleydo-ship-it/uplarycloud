<?php

namespace Tests\Feature;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Exceptions\RemoteCommandException;
use App\Jobs\ProcessApplicationDeploymentJob;
use App\Jobs\RollbackApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationCategory;
use App\Models\ApplicationDeployment;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Deployments\DeploymentService;
use App\Services\Infrastructure\FakeServerExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_and_deployments_are_tenant_scoped(): void
    {
        [$user,$tenant,$server]=$this->owner(); [$otherUser,$otherTenant,$otherServer]=$this->owner(); $app=$this->catalogApp();
        $own=$this->deployment($tenant,$server,$user,$app,'Own Analytics'); $this->deployment($otherTenant,$otherServer,$otherUser,$app,'Foreign Analytics');
        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->get(route('applications.index'))->assertOk()->assertSee($app->name)->assertSee($own->name)->assertDontSee('Foreign Analytics');
    }

    public function test_marketplace_filters_apps_by_pricing_model(): void
    {
        [$user, $tenant] = $this->owner();
        $free = $this->catalogApp();
        $free->update(['pricing_model' => 'free']);
        $paid = Application::create([
            'category_id' => $free->category_id,
            'name' => 'Enterprise Console',
            'slug' => 'enterprise-console',
            'description' => 'Commercial management console.',
            'docker_image' => 'example/enterprise-console',
            'pricing_model' => 'paid',
            'license_type' => 'commercial',
            'requires_license' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('applications.index', ['pricing' => 'paid']));

        $response->assertOk()
            ->assertSee($paid->name)
            ->assertSee('Paid license')
            ->assertSee('Vendor license required')
            ->assertDontSee($free->name);
    }

    public function test_owner_can_queue_deployment_with_encrypted_secrets_and_stages(): void
    {
        Bus::fake(); [$user,$tenant,$server]=$this->owner(); $app=$this->catalogApp();
        $response=$this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->post(route('deployments.store'),['application_id'=>$app->id,'deployment_type'=>'marketplace','server_id'=>$server->id,'name'=>'Metrics','docker_image'=>$app->docker_image,'docker_tag'=>'latest','container_port'=>3000,'cpu_limit'=>.5,'memory_limit_mb'=>512,'disk_limit_gb'=>2,'restart_policy'=>'unless-stopped','auto_start'=>1,'environment_keys'=>['ADMIN_PASSWORD'],'environment_values'=>['super-secret-value'],'environment_descriptions'=>['Admin password'],'environment_secrets'=>['0']]);
        $deployment=ApplicationDeployment::firstOrFail(); $response->assertRedirect(route('deployments.show',$deployment)); Bus::assertDispatched(ProcessApplicationDeploymentJob::class);
        $this->assertCount(11,$deployment->steps); $this->assertNotSame('super-secret-value',DB::table('deployment_environment_variables')->value('value')); $this->assertSame('••••••••••••',$deployment->environmentVariables->first()->maskedValue());
    }

    public function test_fake_deployment_engine_creates_runtime_resources_and_release(): void
    {
        [$user,$tenant,$server]=$this->owner(); $app=$this->catalogApp(); $deployment=$this->deployment($tenant,$server,$user,$app,'Automation');
        foreach (array_values(DeploymentService::STAGES) as $position=>$name) { $keys=array_keys(DeploymentService::STAGES); $deployment->steps()->create(['key'=>$keys[$position],'name'=>$name,'position'=>$position+1]); }
        (new ProcessApplicationDeploymentJob($deployment->id,$tenant->id,$user->id))->handle(app(DeploymentService::class));
        $deployment->refresh(); $this->assertSame('running',$deployment->status->value,$deployment->last_error ?? 'Deployment failed'); $this->assertSame(100,$deployment->progress); $this->assertTrue($deployment->releases()->where('is_current',true)->exists()); $this->assertDatabaseHas('docker_containers',['tenant_id'=>$tenant->id,'name'=>$deployment->slug,'status'=>'running']); $this->assertGreaterThan(10,$deployment->logs()->count());
    }

    public function test_fake_driver_refuses_public_ip_deployment(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $server->update(['ip_address' => '142.93.127.29']);
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'Public Host App');
        foreach (array_values(DeploymentService::STAGES) as $position => $name) {
            $keys = array_keys(DeploymentService::STAGES);
            $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1]);
        }

        (new ProcessApplicationDeploymentJob($deployment->id, $tenant->id, $user->id))->handle(app(DeploymentService::class));

        $deployment->refresh();
        $this->assertSame('failed', $deployment->status->value);
        $this->assertStringContainsString('INFRASTRUCTURE_DRIVER=ssh', (string) $deployment->last_error);
    }

    public function test_failed_health_check_does_not_mark_deployment_running(): void
    {
        [$user, $tenant] = $this->owner();
        $server = Server::create([
            'tenant_id' => $tenant->id,
            'name' => 'Unhealthy Host',
            'provider' => 'custom',
            'ip_address' => '203.0.113.80',
            'operating_system' => 'ubuntu-24.04',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
            'cpu_cores' => 4,
            'memory_mb' => 8192,
            'disk_gb' => 160,
        ]);
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'Broken Runtime');
        foreach (array_values(DeploymentService::STAGES) as $position => $name) {
            $keys = array_keys(DeploymentService::STAGES);
            $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1]);
        }

        (new ProcessApplicationDeploymentJob($deployment->id, $tenant->id, $user->id))->handle(app(DeploymentService::class));

        $deployment->refresh();
        $this->assertSame('failed', $deployment->status->value);
        $this->assertNotSame(100, $deployment->progress);
        $this->assertStringContainsString('exited', (string) $deployment->last_error);
        $this->assertFalse($deployment->releases()->where('is_current', true)->exists());
    }

    public function test_failing_docker_command_fails_only_that_step_and_creates_no_release(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'Broken Create');
        $this->seedSteps($deployment);
        $this->bindExecutor(new class extends FakeServerExecutor
        {
            public function execute(Server $server, string $command, ?int $timeoutSeconds = null): string
            {
                if (str_starts_with($command, 'docker create')) {
                    throw new RemoteCommandException(
                        $command,
                        125,
                        '',
                        'docker: Error response from daemon: driver failed programming external connectivity: port is already allocated.',
                        false,
                        180,
                        'The remote infrastructure command failed (exit 125). docker: Error response from daemon: driver failed programming external connectivity: port is already allocated.'
                    );
                }

                return parent::execute($server, $command, $timeoutSeconds);
            }
        });

        (new ProcessApplicationDeploymentJob($deployment->id, $tenant->id, $user->id))->handle(app(DeploymentService::class));

        $deployment->refresh();
        $this->assertSame('failed', $deployment->status->value);
        $this->assertNotSame(100, $deployment->progress);
        $this->assertStringContainsString('port is already allocated', (string) $deployment->last_error);
        $this->assertSame('failed', $deployment->steps()->where('key', 'create_containers')->value('status'));
        $this->assertSame('completed', $deployment->steps()->where('key', 'create_network')->value('status'));
        $this->assertSame('pending', $deployment->steps()->where('key', 'start_services')->value('status'));
        $this->assertSame('pending', $deployment->steps()->where('key', 'complete')->value('status'));
        $this->assertFalse($deployment->releases()->exists());
        $this->assertTrue(
            $deployment->logs()->where('message', 'like', '%port is already allocated%')->exists(),
            'The real Docker stderr should reach the deployment log.'
        );
    }

    public function test_container_is_published_on_a_free_host_port_when_the_requested_one_is_taken(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'Port Conflict');
        $this->seedSteps($deployment);
        $executor = new class extends FakeServerExecutor
        {
            public array $commands = [];

            public function execute(Server $server, string $command, ?int $timeoutSeconds = null): string
            {
                $this->commands[] = $command;

                if (str_contains($command, 'ss -H -ltn')) {
                    return "22\n80\n3000\n8080\n";
                }

                return parent::execute($server, $command, $timeoutSeconds);
            }
        };
        $this->bindExecutor($executor);

        (new ProcessApplicationDeploymentJob($deployment->id, $tenant->id, $user->id))->handle(app(DeploymentService::class));

        $create = collect($executor->commands)->first(fn (string $command) => str_starts_with($command, 'docker create'));
        $this->assertNotNull($create);
        $this->assertStringContainsString("--publish '8081:3000'", $create);
        $this->assertStringContainsString("--network 'port-conflict", $create);
        $this->assertSame('running', $deployment->refresh()->status->value);
    }

    public function test_verify_runtime_marks_false_success_as_failed(): void
    {
        [$user, $tenant] = $this->owner();
        $server = Server::create([
            'tenant_id' => $tenant->id,
            'name' => 'Unhealthy Host',
            'provider' => 'custom',
            'ip_address' => '203.0.113.81',
            'operating_system' => 'ubuntu-24.04',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
            'cpu_cores' => 4,
            'memory_mb' => 8192,
            'disk_gb' => 160,
        ]);
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'False Success');
        $deployment->update(['status' => 'running', 'progress' => 100, 'current_stage' => 'complete']);
        $this->seedSteps($deployment);
        $deployment->steps()->update(['status' => 'completed']);
        $release = $deployment->releases()->create([
            'version' => 'v20260812.145401',
            'image' => $app->docker_image,
            'image_tag' => 'latest',
            'status' => 'successful',
            'is_current' => true,
            'deployed_at' => now(),
        ]);

        $result = app(DeploymentService::class)->verifyRuntime($deployment);

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $deployment->refresh()->status->value);
        $this->assertFalse($release->refresh()->is_current);
        $this->assertSame('failed', $release->status);
        $this->assertSame('failed', $deployment->steps()->where('key', 'health_check')->value('status'));
        $this->assertSame('failed', $deployment->steps()->where('key', 'complete')->value('status'));
    }

    public function test_owner_can_requeue_a_failed_deployment_without_stale_state(): void
    {
        Bus::fake();
        [$user, $tenant, $server] = $this->owner();
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'Retryable');
        $this->seedSteps($deployment);
        $deployment->steps()->update(['status' => 'completed']);
        $deployment->update(['status' => 'failed', 'progress' => 100, 'current_stage' => 'complete', 'last_error' => 'Container was not found on the server.']);
        $release = $deployment->releases()->create([
            'version' => 'v1',
            'image' => $app->docker_image,
            'image_tag' => 'latest',
            'status' => 'successful',
            'is_current' => true,
            'deployed_at' => now(),
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('deployments.redeploy', $deployment))
            ->assertRedirect();

        Bus::assertDispatched(ProcessApplicationDeploymentJob::class);
        $deployment->refresh();
        $this->assertSame('queued', $deployment->status->value);
        $this->assertSame(0, $deployment->progress);
        $this->assertNull($deployment->last_error);
        $this->assertSame(0, $deployment->steps()->where('status', '!=', 'pending')->count());
        $this->assertFalse($release->refresh()->is_current);
    }

    public function test_owner_can_retry_a_stuck_queued_deployment(): void
    {
        Bus::fake();
        [$user, $tenant, $server] = $this->owner();
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'Stuck Queue');
        $this->seedSteps($deployment);
        $deployment->update(['status' => 'queued', 'progress' => 0, 'current_stage' => 'queued']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('deployments.show', $deployment))
            ->assertOk()
            ->assertSee('Retry queue');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('deployments.redeploy', $deployment))
            ->assertRedirect()
            ->assertSessionHas('success', 'Deployment re-queued.');

        Bus::assertDispatched(ProcessApplicationDeploymentJob::class);
        $this->assertSame('queued', $deployment->refresh()->status->value);
    }

    public function test_recover_orphaned_command_redispatches_queued_deployments_without_redis_jobs(): void
    {
        Bus::fake();
        [$user, $tenant, $server] = $this->owner();
        $app = $this->catalogApp();
        $orphaned = $this->deployment($tenant, $server, $user, $app, 'Orphaned Queue');
        // Bypass Eloquent timestamp touching so the grace window is real.
        ApplicationDeployment::whereKey($orphaned->id)->update([
            'status' => 'queued',
            'progress' => 0,
            'current_stage' => 'queued',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('deployments:recover-orphaned', ['--grace' => 30])
            ->assertSuccessful();

        Bus::assertDispatched(ProcessApplicationDeploymentJob::class, fn (ProcessApplicationDeploymentJob $job) => $job->deploymentId === $orphaned->id);
    }

    public function test_viewer_cannot_deploy_and_rollback_is_queued_for_owner(): void
    {
        Bus::fake(); [$owner,$tenant,$server]=$this->owner(); $viewer=User::factory()->create(); $tenant->users()->attach($viewer,['role'=>'viewer']); $app=$this->catalogApp(); $deployment=$this->deployment($tenant,$server,$owner,$app,'Portal'); $release=$deployment->releases()->create(['version'=>'v1','image'=>$app->docker_image,'image_tag'=>'1.0','status'=>'successful','is_current'=>false,'deployed_at'=>now()->subDay()]);
        $payload=['application_id'=>$app->id,'deployment_type'=>'marketplace','server_id'=>$server->id,'name'=>'Blocked','docker_image'=>$app->docker_image,'docker_tag'=>'latest','restart_policy'=>'unless-stopped'];
        $this->actingAs($viewer)->withSession(['tenant_id'=>$tenant->id])->post(route('deployments.store'),$payload)->assertForbidden();
        $this->actingAs($owner)->withSession(['tenant_id'=>$tenant->id])->post(route('deployments.rollback',[$deployment,$release]))->assertRedirect(); Bus::assertDispatched(RollbackApplicationDeploymentJob::class);
    }

    public function test_fake_rollback_switches_the_current_release_without_restoring_secrets(): void
    {
        [$owner,$tenant,$server]=$this->owner(); $app=$this->catalogApp(); $deployment=$this->deployment($tenant,$server,$owner,$app,'Rollback App');
        $old=$deployment->releases()->create(['version'=>'v1','image'=>$app->docker_image,'image_tag'=>'1.0','status'=>'successful','is_current'=>false,'configuration'=>['memory_limit_mb'=>256],'deployed_at'=>now()->subDay()]);
        $current=$deployment->releases()->create(['version'=>'v2','image'=>$app->docker_image,'image_tag'=>'2.0','status'=>'successful','is_current'=>true,'configuration'=>['memory_limit_mb'=>512],'deployed_at'=>now()]);
        app(DeploymentService::class)->rollback($deployment,$old);
        $this->assertTrue($old->refresh()->is_current); $this->assertFalse($current->refresh()->is_current); $this->assertSame('1.0',$deployment->refresh()->docker_tag); $this->assertSame('running',$deployment->status->value); $this->assertDatabaseMissing('deployment_releases',['id'=>$old->id,'configuration'=>'super-secret-value']);
    }

    public function test_installed_page_loads_when_deployment_server_is_soft_deleted(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'Orphaned Analytics');
        $serverName = $server->name;
        $server->delete();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('applications.installed'))
            ->assertOk()
            ->assertSee($deployment->name)
            ->assertSee($serverName)
            ->assertDontSee('Server removed');
    }

    public function test_installed_page_filters_by_server_query_parameter(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $otherServer = Server::create([
            'tenant_id' => $tenant->id,
            'name' => 'Staging',
            'provider' => 'custom',
            'ip_address' => '203.0.113.'.random_int(201, 254),
            'operating_system' => 'ubuntu-24.04',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
            'cpu_cores' => 2,
            'memory_mb' => 4096,
            'disk_gb' => 80,
        ]);
        $app = $this->catalogApp();
        $matching = $this->deployment($tenant, $server, $user, $app, 'Production App');
        $other = $this->deployment($tenant, $otherServer, $user, $app, 'Staging App');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('applications.installed', ['server' => $server->id]))
            ->assertOk()
            ->assertSee($matching->name)
            ->assertDontSee($other->name);
    }

    public function test_installed_page_handles_custom_deploy_without_application(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $deployment = ApplicationDeployment::create([
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'server_id' => $server->id,
            'created_by' => $user->id,
            'name' => 'Custom Nginx',
            'deployment_type' => 'custom',
            'docker_image' => 'nginx',
            'docker_tag' => 'alpine',
            'container_port' => 80,
            'restart_policy' => 'unless-stopped',
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('applications.installed'))
            ->assertOk()
            ->assertSee($deployment->name)
            ->assertSee('Custom');
    }

    public function test_owner_can_delete_deployment_and_is_redirected(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'Failed Gitea');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('deployments.destroy', $deployment));

        $response->assertRedirect(route('applications.installed'))
            ->assertSessionHas('success');
        $this->assertSoftDeleted($deployment);
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'deployment.deleted',
            'subject_id' => $deployment->id,
        ]);
    }

    public function test_viewer_cannot_delete_deployment(): void
    {
        [$owner, $tenant, $server] = $this->owner();
        $viewer = User::factory()->create();
        $tenant->users()->attach($viewer, ['role' => 'viewer']);
        $app = $this->catalogApp();
        $deployment = $this->deployment($tenant, $server, $owner, $app, 'Locked App');

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])
            ->get(route('applications.installed'))
            ->assertOk()
            ->assertSee('Manage')
            ->assertDontSee('Delete application');

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('deployments.destroy', $deployment))
            ->assertForbidden();

        $this->assertDatabaseHas('application_deployments', [
            'id' => $deployment->id,
            'deleted_at' => null,
        ]);
    }

    public function test_delete_deployment_is_tenant_isolated(): void
    {
        [$user, $tenant, $server] = $this->owner();
        [$otherUser, $otherTenant, $otherServer] = $this->owner();
        $app = $this->catalogApp();
        $foreign = $this->deployment($otherTenant, $otherServer, $otherUser, $app, 'Foreign Deploy');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('deployments.destroy', $foreign))
            ->assertNotFound();

        $this->assertDatabaseHas('application_deployments', [
            'id' => $foreign->id,
            'deleted_at' => null,
        ]);
    }

    public function test_wordpress_install_wizard_prefills_required_env_and_secrets(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $app = $this->wordpressApp();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('applications.install', $app));

        $response->assertOk()
            ->assertSee('WORDPRESS_DB_HOST', false)
            ->assertSee('WORDPRESS_DB_USER', false)
            ->assertSee('WORDPRESS_DB_PASSWORD', false)
            ->assertSee('WORDPRESS_DB_NAME', false)
            ->assertSee('wordpress', false)
            ->assertSee('Secrets are encrypted after saving', false);

        $environment = $response->viewData('environment');
        $keys = collect($environment)->pluck('key')->all();
        $this->assertContains('WORDPRESS_DB_PASSWORD', $keys);
        $this->assertContains('WORDPRESS_DB_USER', $keys);

        $password = collect($environment)->firstWhere('key', 'WORDPRESS_DB_PASSWORD');
        $this->assertTrue($password['secret']);
        $this->assertNotSame('', $password['value']);
        $this->assertNotSame('change-me', $password['value']);
        $this->assertGreaterThanOrEqual(16, strlen($password['value']));
    }

    public function test_owner_can_reveal_encrypted_credentials_on_deployment_show(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $app = $this->wordpressApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'Blog');
        $deployment->environmentVariables()->create([
            'key' => 'WORDPRESS_DB_USER',
            'value' => 'wordpress',
            'secret' => false,
            'description' => 'Database username',
        ]);
        $deployment->environmentVariables()->create([
            'key' => 'WORDPRESS_DB_PASSWORD',
            'value' => 'copied-secret-password',
            'secret' => true,
            'description' => 'Database password',
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('deployments.show', $deployment))
            ->assertOk()
            ->assertSee('Environment & credentials', false)
            ->assertSee('copied-secret-password', false)
            ->assertSee('WORDPRESS_DB_USER', false);
    }

    public function test_viewer_cannot_see_decrypted_secret_values_on_deployment_show(): void
    {
        [$owner, $tenant, $server] = $this->owner();
        $viewer = User::factory()->create();
        $tenant->users()->attach($viewer, ['role' => 'viewer']);
        $app = $this->wordpressApp();
        $deployment = $this->deployment($tenant, $server, $owner, $app, 'Private Blog');
        $deployment->environmentVariables()->create([
            'key' => 'WORDPRESS_DB_PASSWORD',
            'value' => 'viewer-must-not-see',
            'secret' => true,
            'description' => 'Database password',
        ]);

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])
            ->get(route('deployments.show', $deployment))
            ->assertOk()
            ->assertDontSee('viewer-must-not-see', false)
            ->assertSee('Secrets stay masked for your role', false)
            ->assertSee('WORDPRESS_DB_PASSWORD', false)
            ->assertSee('\u0022value\u0022:null', false);
    }

    public function test_wordpress_marketplace_deploy_provisions_mariadb_sidecar(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $app = $this->wordpressApp();
        $deployment = $this->deployment($tenant, $server, $user, $app, 'WP Blog');
        $deployment->update(['container_port' => 80, 'domain' => null]);
        $deployment->environmentVariables()->createMany([
            ['key' => 'WORDPRESS_DB_HOST', 'value' => 'db', 'secret' => false],
            ['key' => 'WORDPRESS_DB_USER', 'value' => 'wordpress', 'secret' => false],
            ['key' => 'WORDPRESS_DB_PASSWORD', 'value' => 'super-secret-db-pass', 'secret' => true],
            ['key' => 'WORDPRESS_DB_NAME', 'value' => 'wordpress', 'secret' => false],
        ]);
        $this->seedSteps($deployment);

        $executor = new class extends FakeServerExecutor
        {
            public array $commands = [];

            public function execute(Server $server, string $command, ?int $timeoutSeconds = null): string
            {
                $this->commands[] = $command;

                return parent::execute($server, $command, $timeoutSeconds);
            }
        };
        $this->bindExecutor($executor);

        (new ProcessApplicationDeploymentJob($deployment->id, $tenant->id, $user->id))->handle(app(DeploymentService::class));

        $dbCreate = collect($executor->commands)->first(fn (string $command) => str_contains($command, "docker create --name '".$deployment->slug."-db'"));
        $this->assertNotNull($dbCreate, 'MariaDB sidecar should be created for WordPress.');
        $this->assertStringContainsString('mariadb:11', $dbCreate);
        $this->assertStringContainsString("MYSQL_DATABASE=wordpress", $dbCreate);
        $this->assertStringContainsString("MYSQL_USER=wordpress", $dbCreate);

        $appCreate = collect($executor->commands)->first(fn (string $command) => str_starts_with($command, 'docker create --name \''.$deployment->slug.'\''));
        $this->assertNotNull($appCreate);
        $this->assertStringContainsString('WORDPRESS_DB_HOST='.$deployment->slug.'-db', $appCreate);
        $this->assertStringContainsString('/var/www/html', $appCreate);

        $this->assertSame($deployment->slug.'-db', $deployment->environmentVariables()->where('key', 'WORDPRESS_DB_HOST')->value('value'));
        $this->assertSame('running', $deployment->refresh()->status->value);
    }

    private function seedSteps(ApplicationDeployment $deployment): void
    {
        foreach (array_values(DeploymentService::STAGES) as $position => $name) {
            $keys = array_keys(DeploymentService::STAGES);
            $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1]);
        }
    }

    private function bindExecutor(ServerExecutorInterface $executor): void
    {
        $this->app->instance(ServerExecutorInterface::class, $executor);
    }

    private function owner(): array { $user=User::factory()->create(); $tenant=Tenant::create(['name'=>fake()->unique()->company()]); $tenant->users()->attach($user,['role'=>'owner']); $server=Server::create(['tenant_id'=>$tenant->id,'name'=>'Production','provider'=>'custom','ip_address'=>'203.0.113.'.fake()->unique()->numberBetween(1,254),'operating_system'=>'ubuntu-24.04','status'=>'online','authentication_method'=>'ssh_key','cpu_cores'=>4,'memory_mb'=>8192,'disk_gb'=>160]); return[$user,$tenant,$server]; }
    private function catalogApp(): Application { $category=ApplicationCategory::firstOrCreate(['slug'=>'analytics'],['name'=>'Analytics']); return Application::firstOrCreate(['slug'=>'metrics'],['category_id'=>$category->id,'name'=>'Metrics','description'=>'Private analytics.','docker_image'=>'example/metrics','default_tag'=>'latest','default_port'=>3000,'minimum_memory_mb'=>512,'minimum_disk_gb'=>2]); }
    private function wordpressApp(): Application
    {
        $category = ApplicationCategory::firstOrCreate(['slug' => 'cms'], ['name' => 'CMS']);
        $app = Application::firstOrCreate(['slug' => 'wordpress'], [
            'category_id' => $category->id,
            'name' => 'WordPress',
            'description' => 'The world’s most popular publishing platform.',
            'docker_image' => 'wordpress',
            'default_tag' => 'latest',
            'default_port' => 80,
            'minimum_memory_mb' => 512,
            'minimum_disk_gb' => 5,
            'active' => true,
        ]);
        if (! $app->template) {
            $app->template()->create([
                'compose_template' => "services:\n  app:\n    image: wordpress:latest\n",
                'environment_schema' => [['key' => 'TZ', 'value' => 'Asia/Dubai', 'description' => 'Application timezone', 'secret' => false]],
                'volume_schema' => [['name' => 'data', 'path' => '/var/www/html']],
                'port_schema' => [['container' => 80]],
                'healthcheck' => 'container',
                'restart_policy' => 'unless-stopped',
                'installation_notes' => 'Point WORDPRESS_DB_HOST at a reachable MySQL service before deploying.',
            ]);
        }

        return $app->fresh(['template', 'category']);
    }
    private function deployment(Tenant $tenant,Server $server,User $user,Application $app,string $name): ApplicationDeployment { return ApplicationDeployment::create(['tenant_id'=>$tenant->id,'application_id'=>$app->id,'server_id'=>$server->id,'created_by'=>$user->id,'name'=>$name,'deployment_type'=>'marketplace','docker_image'=>$app->docker_image,'docker_tag'=>'latest','container_port'=>3000,'cpu_limit'=>.5,'memory_limit_mb'=>512,'disk_limit_gb'=>2,'restart_policy'=>'unless-stopped']); }
}
