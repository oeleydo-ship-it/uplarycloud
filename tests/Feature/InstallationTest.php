<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\InstallationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_redirects_to_install(): void
    {
        $this->get('/')->assertRedirect(route('install'));
        $this->get(route('login'))->assertRedirect(route('install'));
        $this->get(route('dashboard'))->assertRedirect(route('install'));
        $this->get(route('install'))->assertOk()->assertSee('Install Uplary Cloud');
    }

    public function test_health_endpoints_remain_public_before_install(): void
    {
        $this->getJson(route('health.live'))->assertOk()->assertJsonPath('status', 'alive');
        $this->getJson(route('health.ready'))->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_install_creates_superadmin_workspace_and_marks_installed(): void
    {
        $response = $this->post(route('install.store'), [
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'platform_name' => 'Uplary Ops',
            'workspace_name' => 'Ops Workspace',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($user->is_super_admin);
        $this->assertNotNull($user->email_verified_at);

        $tenant = Tenant::where('name', 'Ops Workspace')->firstOrFail();
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('settings', [
            'tenant_id' => null,
            'group' => 'platform',
            'key' => 'installed',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('settings', [
            'tenant_id' => null,
            'group' => 'general',
            'key' => 'platform_name',
            'value' => 'Uplary Ops',
        ]);
        $this->assertTrue(app(InstallationState::class)->isInstalled());
    }

    public function test_install_cannot_be_rerun_when_users_exist(): void
    {
        User::factory()->create(['is_super_admin' => true]);

        $this->get(route('install'))->assertRedirect(route('login'));
        $this->post(route('install.store'), [
            'name' => 'Another Admin',
            'email' => 'another@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => 'another@example.com']);
        $this->assertSame(1, User::count());
    }
}
