<?php

namespace Tests\Feature;

use App\Jobs\CreateBackupJob;
use App\Jobs\UpdateDockerImageJob;
use App\Models\AlertRule;
use App\Models\ApplicationDeployment;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\DockerContainer;
use App\Models\DockerImage;
use App\Models\OperationalLog;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Docker\DockerService;
use App\Services\Operations\AlertEvaluationService;
use App\Services\Operations\BackupService;
use App\Services\Operations\MonitoringService;
use App\Services\Operations\OperationsLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metric_collection_records_server_and_container_samples():void
    {
        [$user,$tenant,$server,$deployment,$container]=$this->workspace();app(MonitoringService::class)->collect($server);$this->assertDatabaseCount('server_metrics',1);$this->assertDatabaseCount('container_metrics',1);$this->assertGreaterThan(0,(float)$container->refresh()->cpu_percent);$this->assertDatabaseHas('operational_logs',['tenant_id'=>$tenant->id,'category'=>'server']);
    }

    public function test_ssh_metric_collection_overwrites_fake_hardware_defaults(): void
    {
        config(['infrastructure.driver' => 'ssh']);
        [, , $server] = $this->workspace();
        $server->update(['cpu_cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 160]);

        $executor = \Mockery::mock(\App\Contracts\Infrastructure\ServerExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturnUsing(function (Server $server, string $command): string {
            if (str_contains($command, 'nproc')) {
                return "8\n16777216\n52428800\nID=ubuntu\nVERSION_ID=\"24.04\"\n";
            }
            if (str_contains($command, 'docker ps')) {
                return '';
            }
            if (str_contains($command, 'docker version')) {
                return '28.3.3';
            }
            if (str_contains($command, 'docker compose version')) {
                return '2.38.2';
            }

            return "12.5\n40.0\n55.0\n0.75";
        });
        $this->app->instance(\App\Contracts\Infrastructure\ServerExecutorInterface::class, $executor);

        app(MonitoringService::class)->collect($server->fresh());

        $server->refresh();
        $this->assertSame(8, $server->cpu_cores);
        $this->assertSame(16384, $server->memory_mb);
        $this->assertSame(50, $server->disk_gb);
        $this->assertSame('ubuntu-24.04', $server->operating_system);
        $this->assertDatabaseHas('server_metrics', ['server_id' => $server->id]);
    }

    public function test_alert_evaluation_deduplicates_and_resolves_incidents():void
    {
        [$user,$tenant,$server]=$this->workspace();$server->metrics()->create(['cpu_percent'=>91,'memory_percent'=>40,'disk_percent'=>50,'recorded_at'=>now()]);$rule=AlertRule::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'name'=>'CPU critical','type'=>'cpu_high','metric'=>'cpu','threshold'=>80,'severity'=>'critical','duration_minutes'=>1]);$service=app(AlertEvaluationService::class);$service->evaluate($rule);$service->evaluate($rule->refresh());$this->assertSame(1,$rule->incidents()->count());$server->metrics()->create(['cpu_percent'=>20,'memory_percent'=>40,'disk_percent'=>50,'recorded_at'=>now()->addSecond()]);$service->evaluate($rule->refresh());$this->assertSame('resolved',$rule->incidents()->first()->refresh()->status);
    }

    public function test_owner_can_queue_backup_and_destination_credentials_are_encrypted():void
    {
        Bus::fake();[$user,$tenant,$server,$deployment]=$this->workspace();$this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->post(route('backups.destinations.store'),['name'=>'Object Storage','provider'=>'r2','endpoint'=>'https://example.r2.cloudflarestorage.com','bucket'=>'backups','access_key'=>'access-secret','secret_key'=>'super-secret'])->assertRedirect();$destination=BackupDestination::firstOrFail();$this->assertSame('super-secret',$destination->secret_key);$this->assertNotSame('super-secret',DB::table('backup_destinations')->value('secret_key'));
        $this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->post(route('backups.store'),['application_deployment_id'=>$deployment->id,'backup_destination_id'=>$destination->id,'backup_type'=>'full'])->assertRedirect();Bus::assertDispatched(CreateBackupJob::class);
    }

    public function test_backup_job_creates_downloadable_artifact_and_restore_point():void
    {
        [$user,$tenant,$server,$deployment]=$this->workspace();$backup=Backup::create(['tenant_id'=>$tenant->id,'application_deployment_id'=>$deployment->id,'server_id'=>$server->id,'created_by'=>$user->id,'name'=>'Manual full backup','backup_type'=>'full','status'=>'pending']);(new CreateBackupJob($backup->id,$tenant->id,$user->id))->handle(app(BackupService::class));$backup->refresh();$this->assertSame('successful',$backup->status);$this->assertFileExists($backup->storage_path);$this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->get(route('backups.download',$backup))->assertOk();app(BackupService::class)->restore($backup);$this->assertNotNull($backup->refresh()->restored_at);@unlink($backup->storage_path);
    }

    public function test_viewer_cannot_mutate_backups_alerts_or_monitoring():void
    {
        [$owner,$tenant,$server,$deployment]=$this->workspace();$viewer=User::factory()->create();$tenant->users()->attach($viewer,['role'=>'viewer']);$this->actingAs($viewer)->withSession(['tenant_id'=>$tenant->id])->post(route('backups.store'),['application_deployment_id'=>$deployment->id,'backup_type'=>'full'])->assertForbidden();$this->actingAs($viewer)->withSession(['tenant_id'=>$tenant->id])->post(route('alerts.store'),['name'=>'Blocked','type'=>'cpu_high','server_id'=>$server->id,'threshold'=>80,'duration_minutes'=>5,'severity'=>'warning'])->assertForbidden();$this->actingAs($viewer)->withSession(['tenant_id'=>$tenant->id])->post(route('monitoring.collect'))->assertForbidden();
    }

    public function test_safety_update_creates_backup_and_restarts_matching_container():void
    {
        [$user,$tenant,$server,$deployment,$container]=$this->workspace();$image=DockerImage::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'repository'=>'example/portal','tag'=>'latest','digest'=>'sha256:old','update_available'=>true,'used_by_count'=>1]);$job=new UpdateDockerImageJob($image->id,$tenant->id,$user->id,true);$job->handle(app(DockerService::class),app(BackupService::class),app(OperationsLogService::class));$this->assertFalse($image->refresh()->update_available);$this->assertSame(1,$container->refresh()->restart_count);$backup=Backup::firstOrFail();$this->assertSame('successful',$backup->status);@unlink($backup->storage_path);
    }

    public function test_central_logs_are_tenant_scoped_and_filterable():void
    {
        [$user,$tenant]=$this->workspace();OperationalLog::create(['tenant_id'=>$tenant->id,'category'=>'backup','severity'=>'error','message'=>'Backup unavailable','occurred_at'=>now()]);$other=Tenant::create(['name'=>'Other']);OperationalLog::create(['tenant_id'=>$other->id,'category'=>'system','severity'=>'info','message'=>'Foreign message','occurred_at'=>now()]);$this->actingAs($user)->withSession(['tenant_id'=>$tenant->id])->get(route('logs.index',['category'=>'backup']))->assertOk()->assertSee('Backup unavailable')->assertDontSee('Foreign message');
    }

    public function test_logs_index_accepts_server_query_without_crashing(): void
    {
        [$user, $tenant, $server] = $this->workspace();
        OperationalLog::create([
            'tenant_id' => $tenant->id,
            'server_id' => $server->id,
            'category' => 'server',
            'severity' => 'info',
            'source' => 'nginx',
            'message' => 'Server heartbeat ok',
            'occurred_at' => now(),
        ]);
        OperationalLog::create([
            'tenant_id' => $tenant->id,
            'category' => 'system',
            'severity' => 'warning',
            'source' => 'control-plane',
            'message' => 'Unscoped system note',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('logs.index', ['server' => $server->id, 'range' => '24h']))
            ->assertOk()
            ->assertSee('Server heartbeat ok')
            ->assertDontSee('Unscoped system note');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('logs.index', ['server_id' => $server->id, 'range' => '24h']))
            ->assertOk()
            ->assertSee('Server heartbeat ok');
    }

    public function test_logs_index_loads_when_related_server_is_soft_deleted(): void
    {
        [$user, $tenant, $server] = $this->workspace();
        $serverName = $server->name;
        OperationalLog::create([
            'tenant_id' => $tenant->id,
            'server_id' => $server->id,
            'category' => 'server',
            'severity' => 'error',
            'source' => 'docker',
            'message' => 'Orphaned server log',
            'occurred_at' => now(),
        ]);
        $server->delete();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('logs.index', ['server' => $server->id, 'range' => '24h']))
            ->assertOk()
            ->assertSee('Orphaned server log')
            ->assertSee($serverName);
    }

    public function test_backups_index_loads_when_server_is_soft_deleted(): void
    {
        [$user, $tenant, $server, $deployment] = $this->workspace();
        Backup::create([
            'tenant_id' => $tenant->id,
            'application_deployment_id' => $deployment->id,
            'server_id' => $server->id,
            'created_by' => $user->id,
            'name' => 'Orphaned Snapshot',
            'backup_type' => 'full',
            'status' => 'successful',
        ]);
        $serverName = $server->name;
        $server->delete();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('backups.index'))
            ->assertOk()
            ->assertSee('Orphaned Snapshot')
            ->assertSee($serverName)
            ->assertDontSee('Server removed');
    }

    private function workspace():array
    {
        $user=User::factory()->create();$tenant=Tenant::create(['name'=>fake()->unique()->company()]);$tenant->users()->attach($user,['role'=>'owner']);$plan=Plan::create(['name'=>'Operations Test','slug'=>'ops-test-'.fake()->unique()->numerify('###'),'monthly_price'=>1000,'yearly_price'=>10000,'currency'=>'USD','limits'=>['backups'=>100,'backup_storage_gb'=>1000],'gates'=>['backups'=>true,'s3_destinations'=>true,'monitoring'=>true,'alerts'=>true],'features'=>[],'active'=>true]);$tenant->subscriptions()->create(['plan_id'=>$plan->id,'status'=>'active','billing_cycle'=>'monthly']);$server=Server::create(['tenant_id'=>$tenant->id,'name'=>'Production','provider'=>'custom','ip_address'=>fake()->unique()->ipv4(),'operating_system'=>'ubuntu-24.04','status'=>'online','authentication_method'=>'ssh_key','cpu_cores'=>4,'memory_mb'=>8192,'disk_gb'=>160]);$deployment=ApplicationDeployment::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'created_by'=>$user->id,'name'=>'Portal','slug'=>'portal','deployment_type'=>'custom','docker_image'=>'example/portal','docker_tag'=>'latest','container_port'=>3000,'restart_policy'=>'unless-stopped','status'=>'running']);$container=DockerContainer::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'name'=>'portal','image'=>'example/portal:latest','status'=>'running','health'=>'healthy','memory_limit_mb'=>1024,'restart_count'=>0]);return[$user,$tenant,$server,$deployment,$container];
    }
}
