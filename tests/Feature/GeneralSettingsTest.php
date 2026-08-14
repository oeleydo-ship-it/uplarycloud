<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_workspace_settings_not_platform_admin_console(): void
    {
        [$owner, $tenant] = $this->workspace('owner');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->get(route('settings'))
            ->assertOk()
            ->assertSee('Workspace name')
            ->assertSee('Locale')
            ->assertSee('Console colors')
            ->assertSee('Invite teammates')
            ->assertDontSee('Workspace branding')
            ->assertDontSee('Product identity')
            ->assertDontSee('Choose logo')
            ->assertDontSee('Application name')
            ->assertDontSee('Platform URL')
            ->assertDontSee('Maintenance mode')
            ->assertDontSee('Platform information')
            ->assertDontSee('Tenant management')
            ->assertDontSee('SaaS version');
    }

    public function test_owner_can_update_workspace_settings_only(): void
    {
        [$owner, $tenant] = $this->workspace('owner');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->put(route('settings.update'), [
            'name' => 'Acme Cloud',
            'timezone' => 'Asia/Dubai',
            'language' => 'en',
            'date_format' => 'M j, Y',
            'time_format' => 'g:i A',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('Acme Cloud', $tenant->fresh()->name);
        $this->assertDatabaseHas('settings', ['tenant_id' => $tenant->id, 'group' => 'general', 'key' => 'timezone', 'value' => 'Asia/Dubai']);
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $tenant->id, 'action' => 'settings.general.updated']);
    }

    public function test_tenant_cannot_update_platform_or_maintenance_settings(): void
    {
        [$owner, $tenant] = $this->workspace('owner');
        app(PlatformSettings::class)->put('general', [
            'platform_name' => 'Uplary Cloud',
            'platform_url' => 'https://uplary.test',
            'maintenance_mode' => false,
        ]);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->put(route('settings.update'), [
            'name' => 'Acme Cloud',
            'timezone' => 'UTC',
            'language' => 'en',
            'date_format' => 'M j, Y',
            'time_format' => 'g:i A',
            'platform_url' => 'https://evil.example',
            'maintenance_mode' => '1',
            'platform_name' => 'Hijacked',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('settings', ['tenant_id' => $tenant->id, 'group' => 'general', 'key' => 'maintenance_mode']);
        $this->assertDatabaseMissing('settings', ['tenant_id' => $tenant->id, 'group' => 'general', 'key' => 'platform_url']);
        $this->assertDatabaseMissing('settings', ['tenant_id' => null, 'group' => 'general', 'key' => 'platform_url', 'value' => 'https://evil.example']);
        $this->assertSame('https://uplary.test', (string) app(PlatformSettings::class)->get('general', 'platform_url'));
        $this->assertSame(0, (int) app(PlatformSettings::class)->get('general', 'maintenance_mode'));
        $this->assertSame('Uplary Cloud', (string) app(PlatformSettings::class)->get('general', 'platform_name'));
    }

    public function test_superadmin_can_update_platform_maintenance_from_console(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Platform URL')
            ->assertSee('Maintenance mode')
            ->assertSee('Default language');

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'platform_name' => 'Uplary Ops',
            'platform_url' => 'https://ops.uplary.test',
            'support_email' => 'ops@uplary.test',
            'acme_email' => 'certificates@uplary.test',
            'default_timezone' => 'Asia/Dubai',
            'default_currency' => 'AED',
            'default_language' => 'en',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'maintenance_mode' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('settings', ['tenant_id' => null, 'group' => 'general', 'key' => 'platform_url', 'value' => 'https://ops.uplary.test']);
        $this->assertDatabaseHas('settings', ['tenant_id' => null, 'group' => 'general', 'key' => 'acme_email', 'value' => 'certificates@uplary.test']);
        $this->assertDatabaseHas('settings', ['tenant_id' => null, 'group' => 'general', 'key' => 'maintenance_mode', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['tenant_id' => null, 'group' => 'general', 'key' => 'default_language', 'value' => 'en']);
    }

    public function test_non_superadmin_cannot_update_platform_settings(): void
    {
        [$owner, $tenant] = $this->workspace('owner');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->put(route('admin.settings.update'), [
                'platform_name' => 'Hijack',
                'platform_url' => 'https://evil.example',
                'support_email' => 'evil@example.com',
                'default_timezone' => 'UTC',
                'default_currency' => 'USD',
                'maintenance_mode' => '1',
            ])->assertForbidden();

        $this->assertDatabaseMissing('settings', ['tenant_id' => null, 'group' => 'general', 'key' => 'platform_name', 'value' => 'Hijack']);
    }

    public function test_superadmin_using_tenant_console_cannot_change_platform_settings(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $tenant = Tenant::create(['name' => 'Ops Workspace']);
        $tenant->users()->attach($admin, ['role' => 'owner', 'is_active' => true]);
        app(PlatformSettings::class)->put('general', [
            'platform_url' => 'https://uplary.test',
            'maintenance_mode' => false,
        ]);

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])->put(route('settings.update'), [
            'name' => 'Ops Workspace',
            'timezone' => 'UTC',
            'language' => 'en',
            'date_format' => 'M j, Y',
            'time_format' => 'g:i A',
            'maintenance_mode' => '1',
            'platform_url' => 'https://evil.example',
        ])->assertRedirect();

        $this->assertSame(0, (int) app(PlatformSettings::class)->get('general', 'maintenance_mode'));
        $this->assertSame('https://uplary.test', (string) app(PlatformSettings::class)->get('general', 'platform_url'));
    }

    public function test_viewer_cannot_update_general_settings(): void
    {
        [$viewer, $tenant] = $this->workspace('viewer');
        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])->put(route('settings.update'), [])->assertForbidden();
    }

    public function test_owner_can_update_console_colors_only(): void
    {
        [$owner, $tenant] = $this->workspace('owner');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->put(route('settings.update'), [
            'name' => 'Reference Workspace',
            'timezone' => 'UTC',
            'language' => 'en',
            'date_format' => 'M j, Y',
            'time_format' => 'g:i A',
            'primary_color' => '#FF5500',
            'secondary_color' => '#221133',
            'short_name' => 'XX',
            'tagline' => 'Hijacked',
            'company_name' => 'Stolen Co',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('settings', ['tenant_id' => $tenant->id, 'group' => 'theme', 'key' => 'primary_color', 'value' => '#FF5500']);
        $this->assertDatabaseHas('settings', ['tenant_id' => $tenant->id, 'group' => 'theme', 'key' => 'secondary_color', 'value' => '#221133']);
        $this->assertDatabaseMissing('settings', ['tenant_id' => $tenant->id, 'group' => 'branding', 'key' => 'short_name', 'value' => 'XX']);
        $this->assertDatabaseMissing('settings', ['tenant_id' => $tenant->id, 'group' => 'branding', 'key' => 'tagline', 'value' => 'Hijacked']);
        $this->assertDatabaseMissing('settings', ['tenant_id' => null, 'group' => 'branding', 'key' => 'short_name', 'value' => 'XX']);
    }

    public function test_tenant_cannot_open_full_branding_editor(): void
    {
        [$owner, $tenant] = $this->workspace('owner');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get('/settings/branding')->assertNotFound();
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->put('/settings/branding', ['name' => 'Hijack'])->assertNotFound();
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('admin.branding'))->assertForbidden();
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->put(route('admin.branding.update'), [
                'name' => 'Hijack', 'short_name' => 'HJ', 'primary_color' => '#000000', 'secondary_color' => '#111111',
            ])->assertForbidden();
    }

    private function workspace(string $role): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Reference Workspace']);
        $tenant->users()->attach($user, ['role' => $role, 'is_active' => true]);

        return [$user, $tenant];
    }
}
