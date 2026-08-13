<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformFeatureControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_persist_platform_feature_controls(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'platform_name' => 'Uplary Cloud',
            'platform_url' => 'https://uplary.test',
            'support_email' => 'support@uplary.test',
            'default_timezone' => 'UTC',
            'default_currency' => 'USD',
            'marketplace_enabled' => 1,
            'monitoring_enabled' => 1,
            'support_enabled' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(app(PlatformSettings::class)->featureEnabled('marketplace'));
        $this->assertTrue(app(PlatformSettings::class)->featureEnabled('monitoring'));
        $this->assertFalse(app(PlatformSettings::class)->featureEnabled('support'));
    }

    public function test_disabled_platform_feature_is_blocked_for_tenants(): void
    {
        [$user, $tenant] = $this->workspace();
        app(PlatformSettings::class)->put('general', ['support_enabled' => false]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('support.index'))
            ->assertForbidden()
            ->assertSee('temporarily unavailable')
            ->assertSee('Support');
    }

    public function test_platform_features_are_enabled_by_default(): void
    {
        [$user, $tenant] = $this->workspace();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('support.index'))
            ->assertOk();
    }

    private function workspace(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Feature Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner', 'is_active' => true]);

        return [$user, $tenant];
    }
}
