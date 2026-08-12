<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ServerConnectionPrecheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_driver_connection_precheck_succeeds(): void
    {
        [$user, $tenant] = $this->member('owner');

        $this->assertSame('fake', config('infrastructure.driver'));
        $this->assertInstanceOf(
            \App\Services\Infrastructure\FakeServerExecutor::class,
            app(\App\Contracts\Infrastructure\ServerExecutorInterface::class)
        );

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('servers.validate-connection'), $this->payload());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('driver', 'fake')
            ->assertJsonPath('checks.0.key', 'ssh')
            ->assertJsonPath('checks.0.passed', true)
            ->assertJsonPath('system.cpu_cores', 4)
            ->assertJsonPath('system.memory_mb', 8192)
            ->assertJsonPath('message', 'Fake driver — simulated pre-check passed (not a live SSH connection).');

        $this->assertTrue(collect($response->json('checks'))->every(fn (array $check) => $check['passed'] === true));
        $this->assertSame(0, Server::count());
    }

    public function test_ssh_driver_binding_resolves_ssh_executor(): void
    {
        config(['infrastructure.driver' => 'ssh']);
        $this->app->forgetInstance(\App\Contracts\Infrastructure\ServerExecutorInterface::class);

        $this->assertInstanceOf(
            \App\Services\Infrastructure\SSHServerExecutor::class,
            app(\App\Contracts\Infrastructure\ServerExecutorInterface::class)
        );
    }

    public function test_fake_driver_connection_precheck_reports_ssh_failure(): void
    {
        [$user, $tenant] = $this->member('owner');

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('servers.validate-connection'), $this->payload([
                'private_key' => 'invalid-connection-key',
            ]));

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('driver', 'fake')
            ->assertJsonPath('message', 'SSH authentication failed.')
            ->assertJsonPath('checks.0.key', 'ssh')
            ->assertJsonPath('checks.0.passed', false);

        $this->assertSame(0, Server::count());
    }

    public function test_fake_driver_connection_precheck_reports_resource_failure(): void
    {
        [$user, $tenant] = $this->member('owner');

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('servers.validate-connection'), $this->payload([
                'private_key' => 'low-memory-key',
            ]));

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['key' => 'memory', 'passed' => false]);

        $memory = collect($response->json('checks'))->firstWhere('key', 'memory');
        $this->assertSame('At least 2 GB of RAM is required.', $memory['message']);
        $this->assertSame(0, Server::count());
    }

    public function test_viewer_cannot_run_connection_precheck(): void
    {
        [$user, $tenant] = $this->member('viewer');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('servers.validate-connection'), $this->payload())
            ->assertForbidden();
    }

    public function test_pending_server_can_be_retried(): void
    {
        Bus::fake();
        [$user, $tenant] = $this->member('owner');
        $server = Server::create(array_merge($this->serverAttributes(), [
            'tenant_id' => $tenant->id,
            'status' => 'pending',
        ]));
        $server->provisioningSteps()->create([
            'key' => 'connect',
            'label' => 'Connecting',
            'position' => 1,
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->post(route('servers.provisioning.retry', $server))
            ->assertRedirect(route('servers.provisioning', $server));

        $this->assertSame('pending', $server->refresh()->status->value);
        Bus::assertDispatched(\App\Jobs\ProvisionServerJob::class);
    }

    private function member(string $role): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($user, ['role' => $role]);

        return [$user, $tenant];
    }

    public function test_platform_key_precheck_uses_tenant_keypair_without_pasted_private_key(): void
    {
        [$user, $tenant] = $this->member('owner');

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('servers.validate-connection'), $this->payload([
                'authorization_method' => 'platform_key',
                'private_key' => null,
            ]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('driver', 'fake');

        $this->assertDatabaseHas('settings', [
            'tenant_id' => $tenant->id,
            'group' => 'platform_ssh',
            'key' => 'public_key',
        ]);
        $this->assertDatabaseHas('settings', [
            'tenant_id' => $tenant->id,
            'group' => 'platform_ssh',
            'key' => 'private_key',
            'is_encrypted' => 1,
        ]);
    }

    public function test_platform_key_precheck_rejects_missing_authorization_method_when_no_private_key(): void
    {
        [$user, $tenant] = $this->member('owner');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->postJson(route('servers.validate-connection'), $this->payload([
                'authorization_method' => 'credentials',
                'private_key' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['private_key']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'ip_address' => '203.0.113.40',
            'operating_system' => 'ubuntu-24.04',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'authorization_method' => 'credentials',
            'authentication_method' => 'ssh_key',
            'private_key' => 'demo-private-key',
            'connection_timeout' => 15,
            'install_docker' => true,
        ], $overrides);
    }

    private function serverAttributes(): array
    {
        return [
            'name' => 'Production',
            'provider' => 'custom',
            'ip_address' => '203.0.113.40',
            'location' => 'Dubai',
            'operating_system' => 'ubuntu-24.04',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'authentication_method' => 'ssh_key',
            'connection_timeout' => 15,
        ];
    }
}
