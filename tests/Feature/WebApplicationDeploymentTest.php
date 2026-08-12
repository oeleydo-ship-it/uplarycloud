<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebApplicationDeploymentJob;
use App\Models\ApplicationDeployment;
use App\Models\BuildPack;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Deployments\DeploymentService;
use App\Services\Deployments\WebApplicationDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebApplicationDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_queue_a_git_deployment_with_encrypted_credentials(): void
    {
        Bus::fake();
        [$user, $tenant, $server] = $this->owner();
        $pack = $this->buildPack();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post(route('applications.web.store'), $this->payload($pack, $server, [
            'deploy_key' => 'private-deploy-key',
            'auto_deploy' => 1,
            'environment_keys' => ['APP_TOKEN'],
            'environment_values' => ['top-secret'],
            'environment_secrets' => ['0'],
        ]));

        $deployment = ApplicationDeployment::firstOrFail();
        $response->assertRedirect(route('deployments.show', $deployment));
        Bus::assertDispatched(ProcessWebApplicationDeploymentJob::class);
        $this->assertCount(12, $deployment->steps);
        $this->assertSame('private-deploy-key', $deployment->deploy_key);
        $this->assertNotSame('private-deploy-key', DB::table('application_deployments')->value('deploy_key'));
        $this->assertNotSame('top-secret', DB::table('deployment_environment_variables')->value('value'));
        $this->assertNotEmpty($deployment->webhook_secret);
    }

    public function test_git_deployment_can_enable_redis_queue_and_reverb_sidecars(): void
    {
        Bus::fake();
        [$user, $tenant, $server] = $this->owner();
        $pack = $this->buildPack();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post(route('applications.web.store'), $this->payload($pack, $server, [
            'enable_queue' => 1,
            'enable_reverb' => 1,
        ]))->assertRedirect();

        $deployment = ApplicationDeployment::firstOrFail();
        $this->assertTrue($deployment->enable_redis);
        $this->assertTrue($deployment->enable_queue);
        $this->assertTrue($deployment->enable_reverb);
    }

    public function test_fake_web_build_with_sidecars_completes_successfully(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $deployment = $this->deployment($tenant, $server, $user, $this->buildPack());
        $deployment->update(['enable_redis' => true, 'enable_queue' => true, 'enable_reverb' => true, 'database_engine' => 'mysql']);

        foreach (array_values(WebApplicationDeploymentService::STAGES) as $position => $name) {
            $keys = array_keys(WebApplicationDeploymentService::STAGES);
            $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1]);
        }

        (new ProcessWebApplicationDeploymentJob($deployment->id, $tenant->id, $user->id))->handle(app(WebApplicationDeploymentService::class), app(DeploymentService::class));

        $deployment->refresh();
        $this->assertSame('running', $deployment->status->value, $deployment->last_error ?? 'Build failed');
        $this->assertTrue($deployment->logs()->where('message', 'like', '%Redis%')->exists());
        $this->assertTrue($deployment->logs()->where('message', 'like', '%Queue worker%')->exists());
        $this->assertTrue($deployment->logs()->where('message', 'like', '%Reverb%')->exists());
        $this->assertTrue($deployment->logs()->where('message', 'like', '%MariaDB%')->exists() || $deployment->logs()->where('message', 'like', '%database sidecar%')->exists());
        $this->assertSame('mysql', $deployment->database_engine);
        $this->assertNotNull($deployment->environmentVariables()->where('key', 'DB_HOST')->value('value'));
        $this->assertNotNull($deployment->environmentVariables()->where('key', 'APP_KEY')->value('value'));
    }

    public function test_laravel_runtime_forces_https_asset_url_when_domain_set(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $deployment = $this->deployment($tenant, $server, $user, $this->buildPack());
        $deployment->update(['domain' => 'app.example.com', 'database_engine' => 'mysql']);

        foreach (array_values(WebApplicationDeploymentService::STAGES) as $position => $name) {
            $keys = array_keys(WebApplicationDeploymentService::STAGES);
            $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1]);
        }

        (new ProcessWebApplicationDeploymentJob($deployment->id, $tenant->id, $user->id))->handle(app(WebApplicationDeploymentService::class), app(DeploymentService::class));

        $deployment->refresh();
        $this->assertSame('https://app.example.com', $deployment->environmentVariables()->where('key', 'APP_URL')->value('value'));
        $this->assertSame('https://app.example.com', $deployment->environmentVariables()->where('key', 'ASSET_URL')->value('value'));
        $this->assertSame('*', $deployment->environmentVariables()->where('key', 'TRUSTED_PROXIES')->value('value'));
    }

    public function test_laravel_git_deploy_defaults_to_mysql_when_database_blank(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $deployment = $this->deployment($tenant, $server, $user, $this->buildPack());
        $deployment->update(['database_engine' => null]);

        foreach (array_values(WebApplicationDeploymentService::STAGES) as $position => $name) {
            $keys = array_keys(WebApplicationDeploymentService::STAGES);
            $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1]);
        }

        (new ProcessWebApplicationDeploymentJob($deployment->id, $tenant->id, $user->id))->handle(app(WebApplicationDeploymentService::class), app(DeploymentService::class));

        $deployment->refresh();
        $this->assertSame('running', $deployment->status->value, $deployment->last_error ?? 'Build failed');
        $this->assertSame('mysql', $deployment->database_engine);
        $this->assertSame($deployment->slug.'-db', $deployment->environmentVariables()->where('key', 'DB_HOST')->value('value'));
    }

    public function test_owner_can_redeploy_a_failed_git_application(): void
    {
        Bus::fake();
        [$user, $tenant, $server] = $this->owner();
        $deployment = $this->deployment($tenant, $server, $user, $this->buildPack());
        foreach (array_values(WebApplicationDeploymentService::STAGES) as $position => $name) {
            $keys = array_keys(WebApplicationDeploymentService::STAGES);
            $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1, 'status' => 'failed']);
        }
        $deployment->update(['status' => 'failed', 'progress' => 33, 'current_stage' => 'build_image', 'last_error' => 'timeout']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('deployments.redeploy', $deployment))
            ->assertRedirect()
            ->assertSessionHas('success', 'Redeployment queued.');

        Bus::assertDispatched(ProcessWebApplicationDeploymentJob::class);
        $this->assertSame('queued', $deployment->refresh()->status->value);
    }

    public function test_repository_and_shell_commands_are_strictly_validated(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $pack = $this->buildPack();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post(route('applications.web.store'), $this->payload($pack, $server, [
            'repository_url' => 'https://untrusted.example/repository.git',
            'build_command' => 'npm run build && curl example.com',
        ]))->assertSessionHasErrors(['repository_url']);

        $this->assertDatabaseCount('application_deployments', 0);
    }

    public function test_fake_web_build_creates_release_and_runtime_records(): void
    {
        [$user, $tenant, $server] = $this->owner();
        $deployment = $this->deployment($tenant, $server, $user, $this->buildPack());

        foreach (array_values(WebApplicationDeploymentService::STAGES) as $position => $name) {
            $keys = array_keys(WebApplicationDeploymentService::STAGES);
            $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1]);
        }

        (new ProcessWebApplicationDeploymentJob($deployment->id, $tenant->id, $user->id))->handle(app(WebApplicationDeploymentService::class), app(DeploymentService::class));

        $deployment->refresh();
        $this->assertSame('running', $deployment->status->value, $deployment->last_error ?? 'Build failed');
        $this->assertSame('successful', $deployment->build_status);
        $this->assertNotEmpty($deployment->commit_hash);
        $this->assertTrue($deployment->releases()->where('is_current', true)->exists());
        $this->assertDatabaseHas('docker_containers', ['tenant_id' => $tenant->id, 'name' => $deployment->slug, 'status' => 'running']);
    }

    public function test_signed_git_webhook_queues_a_fresh_deployment(): void
    {
        Bus::fake();
        [$user, $tenant, $server] = $this->owner();
        $deployment = $this->deployment($tenant, $server, $user, $this->buildPack());
        $deployment->update(['auto_deploy' => true, 'webhook_secret' => 'webhook-secret', 'status' => 'running']);
        $payload = json_encode(['after' => str_repeat('a', 40)], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $payload, 'webhook-secret');

        $this->call('POST', route('hooks.git', $deployment), [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertAccepted()->assertJson(['accepted' => true]);

        $this->assertSame('queued', $deployment->refresh()->status->value);
        $this->assertSame(str_repeat('a', 40), $deployment->commit_hash);
        Bus::assertDispatched(ProcessWebApplicationDeploymentJob::class);
    }

    public function test_viewer_cannot_create_a_git_deployment(): void
    {
        [$owner, $tenant, $server] = $this->owner();
        $viewer = User::factory()->create();
        $tenant->users()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])->post(route('applications.web.store'), $this->payload($this->buildPack(), $server))->assertForbidden();
    }

    private function owner(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($user, ['role' => 'owner']);
        $server = Server::create(['tenant_id' => $tenant->id, 'name' => 'Production', 'provider' => 'custom', 'ip_address' => '203.0.113.'.fake()->unique()->numberBetween(1, 254), 'operating_system' => 'ubuntu-24.04', 'status' => 'online', 'authentication_method' => 'ssh_key', 'cpu_cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 160]);
        return [$user, $tenant, $server];
    }

    private function buildPack(): BuildPack
    {
        return BuildPack::firstOrCreate(['slug' => 'laravel'], ['name' => 'Laravel', 'framework' => 'laravel', 'icon' => 'panels-top-left', 'detectors' => ['artisan'], 'runtime_versions' => ['8.4', '8.3', '8.5'], 'defaults' => ['package_manager' => 'composer', 'install_command' => 'composer install --no-dev --optimize-autoloader', 'build_command' => 'npm run build', 'start_command' => 'php artisan serve', 'container_port' => 8000]]);
    }

    private function payload(BuildPack $pack, Server $server, array $overrides = []): array
    {
        return array_merge(['build_pack_id' => $pack->id, 'server_id' => $server->id, 'name' => 'Customer Portal', 'git_provider' => 'github', 'repository_url' => 'https://github.com/uplary/customer-portal.git', 'branch' => 'main', 'runtime_version' => '8.4', 'root_directory' => '/', 'package_manager' => 'composer', 'install_command' => 'composer install --no-dev --optimize-autoloader', 'build_command' => 'npm run build', 'start_command' => 'php artisan serve', 'container_port' => 8000, 'cpu_limit' => .5, 'memory_limit_mb' => 512, 'disk_limit_gb' => 2], $overrides);
    }

    private function deployment(Tenant $tenant, Server $server, User $user, BuildPack $pack): ApplicationDeployment
    {
        return ApplicationDeployment::create(['tenant_id' => $tenant->id, 'build_pack_id' => $pack->id, 'server_id' => $server->id, 'created_by' => $user->id, 'name' => 'Customer Portal', 'deployment_type' => 'git', 'framework' => 'laravel', 'docker_image' => 'platform/customer-portal', 'docker_tag' => 'latest', 'container_port' => 8000, 'cpu_limit' => .5, 'memory_limit_mb' => 512, 'disk_limit_gb' => 2, 'restart_policy' => 'unless-stopped', 'git_provider' => 'github', 'repository_url' => 'https://github.com/uplary/customer-portal.git', 'branch' => 'main', 'runtime_version' => '8.4', 'root_directory' => '/', 'package_manager' => 'composer', 'install_command' => 'composer install --no-dev --optimize-autoloader', 'build_command' => 'npm run build', 'start_command' => 'php artisan serve']);
    }
}
