<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationDeployment;
use App\Services\Applications\CatalogEnvironmentFactory;
use App\Services\Deployments\DeploymentService;
use Database\Seeders\ApplicationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class OpenClawMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_contains_installable_openclaw_template(): void
    {
        $this->seed(ApplicationCatalogSeeder::class);

        $application = Application::with(['category', 'template'])->where('slug', 'openclaw')->firstOrFail();

        $this->assertSame('OpenClaw', $application->name);
        $this->assertSame('AI', $application->category->name);
        $this->assertSame('ghcr.io/openclaw/openclaw', $application->docker_image);
        $this->assertSame('latest', $application->default_tag);
        $this->assertSame(18789, $application->default_port);
        $this->assertSame('/home/node/.openclaw', $application->template->volume_schema[0]['path']);
        $this->assertStringContainsString('openclaw-data:/home/node/.openclaw', $application->template->compose_template);
    }

    public function test_openclaw_environment_generates_gateway_token_and_public_origin(): void
    {
        $schema = collect(app(CatalogEnvironmentFactory::class)->schemaFor('openclaw'))->keyBy('key');
        $generated = collect(app(CatalogEnvironmentFactory::class)->withGeneratedSecrets($schema->values()->all()))->keyBy('key');

        $this->assertTrue($schema['OPENCLAW_GATEWAY_TOKEN']['secret']);
        $this->assertNotSame('', $generated['OPENCLAW_GATEWAY_TOKEN']['value']);
        $this->assertSame('https://openclaw.example.com', $generated['OPENCLAW_PUBLIC_ORIGIN']['value']);
        $this->assertSame('/home/node/.openclaw', $generated['OPENCLAW_STATE_DIR']['value']);
    }

    public function test_openclaw_container_command_bootstraps_and_secures_gateway(): void
    {
        $application = new Application(['slug' => 'openclaw']);
        $deployment = new ApplicationDeployment;
        $deployment->setRelation('application', $application);
        $method = new ReflectionMethod(DeploymentService::class, 'applicationCommandArgs');
        $command = $method->invoke(app(DeploymentService::class), $deployment);

        $this->assertStringContainsString('onboard --non-interactive', $command);
        $this->assertStringContainsString('gateway.controlUi.allowedOrigins', $command);
        $this->assertStringContainsString('gateway --bind lan --port 18789', $command);
    }
}
