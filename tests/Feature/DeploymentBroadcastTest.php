<?php

namespace Tests\Feature;

use App\Events\DeploymentProgressed;
use App\Models\ApplicationDeployment;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DeploymentBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployment_progress_event_broadcasts_to_tenant_channel(): void
    {
        Event::fake([DeploymentProgressed::class]);

        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($user, ['role' => 'owner']);
        $server = Server::create(['tenant_id' => $tenant->id, 'name' => 'Production', 'provider' => 'custom', 'ip_address' => fake()->unique()->ipv4(), 'operating_system' => 'ubuntu-24.04', 'status' => 'online', 'authentication_method' => 'ssh_key', 'cpu_cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 160]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post(route('deployments.store'), [
            'deployment_type' => 'custom',
            'server_id' => $server->id,
            'name' => 'Realtime App',
            'docker_image' => 'nginx',
            'docker_tag' => 'latest',
            'restart_policy' => 'unless-stopped',
        ])->assertRedirect();

        Event::assertDispatched(DeploymentProgressed::class, function (DeploymentProgressed $event): bool {
            $deployment = ApplicationDeployment::firstOrFail();

            return $event->tenantId === $deployment->tenant_id
                && $event->deploymentUuid === $deployment->uuid
                && $event->status === 'queued'
                && $event->stage === 'queued';
        });
    }

    public function test_deployment_progressed_payload_is_structured_for_echo(): void
    {
        $event = new DeploymentProgressed(1, 'deployment-uuid', 'deploying', 42, 'pull_image');

        $this->assertSame('deployment.progressed', $event->broadcastAs());
        $this->assertSame([
            'deploymentUuid' => 'deployment-uuid',
            'status' => 'deploying',
            'progress' => 42,
            'stage' => 'pull_image',
        ], $event->broadcastWith());
    }
}
