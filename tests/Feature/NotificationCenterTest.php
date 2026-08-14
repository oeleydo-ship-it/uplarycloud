<?php

namespace Tests\Feature;

use App\Models\ApplicationDeployment;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployment_completion_notifies_active_operational_members_and_can_be_read(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Notification Workspace']);
        $tenant->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $tenant->users()->attach($viewer, ['role' => 'viewer', 'is_active' => true]);
        $server = $this->server($tenant);
        $deployment = ApplicationDeployment::create([
            'tenant_id' => $tenant->id,
            'server_id' => $server->id,
            'created_by' => $owner->id,
            'name' => 'WordPress',
            'deployment_type' => 'marketplace',
            'docker_image' => 'wordpress',
            'docker_tag' => 'latest',
            'status' => 'deploying',
        ]);

        $deployment->update(['status' => 'running', 'progress' => 100]);

        $notification = $owner->notifications()->firstOrFail();
        $this->assertSame('Deployment completed', $notification->data['title']);
        $this->assertSame('success', $notification->data['severity']);
        $this->assertSame(0, $viewer->notifications()->count());

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Deployment completed')
            ->assertSee('1 unread');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('notifications.read', $notification->id))
            ->assertRedirect(route('deployments.show', $deployment, absolute: false));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_server_failure_creates_error_notification_and_mark_all_is_tenant_scoped(): void
    {
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Primary Workspace']);
        $otherTenant = Tenant::create(['name' => 'Other Workspace']);
        $tenant->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $otherTenant->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);

        $server = $this->server($tenant);
        $otherServer = $this->server($otherTenant);
        $server->update(['status' => 'failed', 'failure_reason' => 'SSH connection refused']);
        $otherServer->update(['status' => 'failed', 'failure_reason' => 'Cloud API timeout']);

        $this->assertSame(2, $owner->unreadNotifications()->count());

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(1, $owner->unreadNotifications()->count());
        $this->assertSame($otherTenant->id, (int) $owner->unreadNotifications()->firstOrFail()->data['tenant_id']);
    }

    public function test_deployment_log_styles_keep_the_console_readable(): void
    {
        $css = file_get_contents(resource_path('css/console-v4.css'));

        $this->assertStringContainsString('.app-body .deployment-show-page .deployment-reference-logs', $css);
        $this->assertStringContainsString('background: #0f172a', $css);
        $this->assertStringContainsString('.deployment-reference-terminal em', $css);
    }

    private function server(Tenant $tenant): Server
    {
        return Server::create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'provider' => 'custom',
            'ip_address' => fake()->unique()->ipv4(),
            'operating_system' => 'ubuntu-24.04',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
            'cpu_cores' => 2,
            'memory_mb' => 2048,
            'disk_gb' => 40,
        ]);
    }
}
