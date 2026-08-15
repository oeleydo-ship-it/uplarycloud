<?php

namespace Tests\Unit;

use App\Models\ApplicationDeployment;
use App\Models\BuildPack;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Deployments\WebApplicationDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaravelDockerfileTest extends TestCase
{
    use RefreshDatabase;
    public function test_laravel_dockerfile_skips_composer_scripts_and_sets_build_app_key(): void
    {
        $deployment = $this->deployment();
        $dockerfile = $this->dockerfileFor($deployment);

        $this->assertStringContainsString('--no-scripts', $dockerfile);
        $this->assertStringContainsString('APP_KEY=base64:', $dockerfile);
        $this->assertStringContainsString('php artisan package:discover', $dockerfile);
        $this->assertStringContainsString('NODE_OPTIONS=--max-old-space-size=768', $dockerfile);
        $this->assertStringContainsString('php:8.4-cli-alpine', $dockerfile);
    }

    private function deployment(): ApplicationDeployment
    {
        $tenant = Tenant::create(['name' => 'Dockerfile Test']);
        $user = User::factory()->create();
        $pack = BuildPack::firstOrCreate(['slug' => 'laravel'], [
            'name' => 'Laravel',
            'framework' => 'laravel',
            'icon' => 'panels-top-left',
            'detectors' => ['artisan'],
            'runtime_versions' => ['8.4', '8.5'],
            'defaults' => [
                'package_manager' => 'composer',
                'install_command' => 'composer install --no-dev --optimize-autoloader',
                'build_command' => 'npm run build',
                'start_command' => 'php artisan serve',
                'container_port' => 8000,
            ],
        ]);
        $server = Server::create([
            'tenant_id' => $tenant->id,
            'name' => 'Build Host',
            'provider' => 'custom',
            'ip_address' => '203.0.113.10',
            'operating_system' => 'ubuntu-24.04',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
            'cpu_cores' => 2,
            'memory_mb' => 2048,
            'disk_gb' => 40,
        ]);

        return ApplicationDeployment::create([
            'tenant_id' => $tenant->id,
            'build_pack_id' => $pack->id,
            'server_id' => $server->id,
            'created_by' => $user->id,
            'name' => 'Portal',
            'deployment_type' => 'git',
            'framework' => 'laravel',
            'docker_image' => 'platform/portal',
            'docker_tag' => 'latest',
            'container_port' => 8000,
            'runtime_version' => '8.4',
            'git_provider' => 'github',
            'repository_url' => 'https://github.com/example/app.git',
            'branch' => 'main',
            'install_command' => 'composer install --no-dev --optimize-autoloader',
        ]);
    }

    private function dockerfileFor(ApplicationDeployment $deployment): string
    {
        $method = new \ReflectionMethod(WebApplicationDeploymentService::class, 'dockerfile');
        $method->setAccessible(true);

        return $method->invoke(app(WebApplicationDeploymentService::class), $deployment);
    }
}
