<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_cannot_select_a_workspace_without_membership(): void
    {
        $user = User::factory()->create();
        $own = Tenant::create(['name' => 'Own Workspace']);
        $foreign = Tenant::create(['name' => 'Foreign Workspace']);
        $own->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)->withSession(['tenant_id' => $foreign->id])
            ->get('/dashboard')->assertOk()->assertSee('Own Workspace');

        $this->assertSame($own->id, session('tenant_id'));
    }

    public function test_branding_updates_only_the_active_workspace(): void
    {
        $user = User::factory()->create();
        $own = Tenant::create(['name' => 'Own Workspace']);
        $foreign = Tenant::create(['name' => 'Foreign Workspace']);
        $own->users()->attach($user, ['role' => 'owner']);
        Setting::create(['tenant_id' => $foreign->id, 'group' => 'branding', 'key' => 'name', 'value' => 'Foreign Brand']);

        $this->actingAs($user)->withSession(['tenant_id' => $own->id])->put('/settings/branding', [
            'name' => 'Own Brand', 'short_name' => 'OB', 'tagline' => '',
            'primary_color' => '#6C4CF5', 'secondary_color' => '#17152B',
            'company_name' => '', 'website' => '', 'support_email' => '',
            'documentation_url' => '', 'copyright' => '',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', ['tenant_id' => $own->id, 'key' => 'name', 'value' => 'Own Brand']);
        $this->assertDatabaseHas('settings', ['tenant_id' => $foreign->id, 'key' => 'name', 'value' => 'Foreign Brand']);
    }

    public function test_viewer_cannot_update_branding(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Read Only']);
        $tenant->users()->attach($user, ['role' => 'viewer']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->put('/settings/branding', [])->assertForbidden();
    }

    public function test_dashboard_actions_link_to_real_destinations(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Dashboard Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('servers.create'), false)
            ->assertSee(route('servers.index'), false)
            ->assertSee(route('applications.index'), false)
            ->assertSee(route('backups.index'), false)
            ->assertSee(route('monitoring.index'), false)
            ->assertDontSee('href="#"', false);
    }

    public function test_dashboard_only_summarizes_the_active_workspace(): void
    {
        $user = User::factory()->create();
        $own = Tenant::create(['name' => 'Own Dashboard']);
        $foreign = Tenant::create(['name' => 'Foreign Dashboard']);
        $own->users()->attach($user, ['role' => 'owner']);

        Server::create([
            'tenant_id' => $own->id,
            'name' => 'Own Production Server',
            'provider' => 'custom',
            'ip_address' => '192.0.2.10',
            'operating_system' => 'ubuntu-24.04',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
        ]);
        Server::create([
            'tenant_id' => $foreign->id,
            'name' => 'Foreign Secret Server',
            'provider' => 'custom',
            'ip_address' => '192.0.2.20',
            'operating_system' => 'ubuntu-24.04',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $own->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Own Production Server')
            ->assertDontSee('Foreign Secret Server');
    }
}
