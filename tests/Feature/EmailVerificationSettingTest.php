<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_is_blocked_when_verification_is_required(): void
    {
        config(['app.disable_email_verification' => false]);
        app(PlatformSettings::class)->put('general', ['email_verification' => true]);

        $user = User::factory()->unverified()->create();
        $tenant = Tenant::create(['name' => 'Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_user_can_reach_dashboard_when_verification_is_off(): void
    {
        config(['app.disable_email_verification' => false]);
        app(PlatformSettings::class)->put('general', ['email_verification' => false]);

        $user = User::factory()->unverified()->create();
        $tenant = Tenant::create(['name' => 'Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('verification.notice'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_registration_skips_verification_when_setting_is_off(): void
    {
        config(['app.disable_email_verification' => false]);
        app(PlatformSettings::class)->put('general', ['email_verification' => false]);
        User::factory()->create(['is_super_admin' => true]);

        $this->post('/register', [
            'name' => 'Dev User',
            'workspace_name' => 'Dev Labs',
            'email' => 'dev@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'dev@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_requires_verification_when_setting_is_on(): void
    {
        config(['app.disable_email_verification' => false]);
        app(PlatformSettings::class)->put('general', ['email_verification' => true]);
        User::factory()->create(['is_super_admin' => true]);

        $this->post('/register', [
            'name' => 'Ada Developer',
            'workspace_name' => 'Ada Labs',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ])->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
    }

    public function test_config_disable_overrides_platform_setting(): void
    {
        config(['app.disable_email_verification' => true]);
        app(PlatformSettings::class)->put('general', ['email_verification' => true]);

        $this->assertFalse(app(PlatformSettings::class)->emailVerificationRequired());

        $user = User::factory()->unverified()->create();
        $tenant = Tenant::create(['name' => 'Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_superadmin_can_toggle_email_verification_setting(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'platform_name' => 'Uplary',
            'platform_url' => 'https://uplary.test',
            'support_email' => 'support@uplary.test',
            'default_timezone' => 'UTC',
            'default_currency' => 'USD',
            'registration_enabled' => 1,
            // email_verification omitted => off
        ])->assertSessionHasNoErrors();

        $this->assertSame('0', (string) app(PlatformSettings::class)->get('general', 'email_verification'));
        $this->assertFalse(app(PlatformSettings::class)->emailVerificationRequired());

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'platform_name' => 'Uplary',
            'platform_url' => 'https://uplary.test',
            'support_email' => 'support@uplary.test',
            'default_timezone' => 'UTC',
            'default_currency' => 'USD',
            'registration_enabled' => 1,
            'email_verification' => 1,
        ])->assertSessionHasNoErrors();

        config(['app.disable_email_verification' => false]);
        $this->assertTrue(app(PlatformSettings::class)->emailVerificationRequired());
    }
}
