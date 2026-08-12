<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_an_owner_workspace(): void
    {
        User::factory()->create(['is_super_admin' => true]);

        $response = $this->post('/register', [
            'name' => 'Ada Developer', 'workspace_name' => 'Ada Labs',
            'email' => 'ada@example.com', 'password' => 'password123',
            'password_confirmation' => 'password123', 'terms' => '1',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();
        $tenant = Tenant::where('name', 'Ada Labs')->firstOrFail();
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'role' => 'owner']);
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $tenant->id, 'action' => 'workspace.created']);
    }

    public function test_user_can_log_in_and_log_out(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $tenant = Tenant::create(['name' => 'Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
