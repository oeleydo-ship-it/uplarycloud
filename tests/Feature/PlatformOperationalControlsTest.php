<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformOperationalControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_save_operational_controls(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'platform_name' => 'Uplary Cloud',
            'platform_url' => 'https://uplary.test',
            'support_email' => 'support@uplary.test',
            'default_timezone' => 'UTC',
            'default_currency' => 'USD',
            'maintenance_mode' => 1,
            'read_only_mode' => 1,
            'maintenance_message' => 'Planned work is underway.',
        ])->assertSessionHasNoErrors();

        $settings = app(PlatformSettings::class);
        $this->assertSame(1, (int) $settings->get('general', 'maintenance_mode'));
        $this->assertSame(1, (int) $settings->get('general', 'read_only_mode'));
        $this->assertSame('Planned work is underway.', $settings->get('general', 'maintenance_message'));
    }

    public function test_maintenance_mode_restricts_tenant_console_with_custom_message(): void
    {
        [$user, $tenant] = $this->workspace();
        app(PlatformSettings::class)->put('general', [
            'maintenance_mode' => true,
            'maintenance_message' => 'Database maintenance ends at 04:00 UTC.',
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertStatus(503)
            ->assertSee('Scheduled maintenance')
            ->assertSee('Database maintenance ends at 04:00 UTC.');
    }

    public function test_read_only_mode_allows_reads_and_blocks_customer_changes(): void
    {
        [$user, $tenant] = $this->workspace();
        app(PlatformSettings::class)->put('general', ['read_only_mode' => true]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('settings'))->assertOk();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->put(route('settings.update'), [
                'name' => 'Changed Workspace',
                'timezone' => 'UTC',
                'language' => 'en',
                'date_format' => 'M j, Y',
                'time_format' => 'g:i A',
            ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('Operational Workspace', $tenant->fresh()->name);
    }

    public function test_superadmin_bypasses_operational_restrictions(): void
    {
        [$admin, $tenant] = $this->workspace(true);
        app(PlatformSettings::class)->put('general', ['maintenance_mode' => true, 'read_only_mode' => true]);

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))->assertOk();
    }

    private function workspace(bool $superAdmin = false): array
    {
        $user = User::factory()->create(['is_super_admin' => $superAdmin]);
        $tenant = Tenant::create(['name' => 'Operational Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner', 'is_active' => true]);

        return [$user, $tenant];
    }
}
