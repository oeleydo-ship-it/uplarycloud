<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branding;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_update_platform_identity_and_assets(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->get(route('admin.branding'))
            ->assertOk()
            ->assertSee('Product identity')
            ->assertSee('Choose logo')
            ->assertSee('Brand colors');

        $this->actingAs($admin)->put(route('admin.branding.update'), [
            'name' => 'Uplary Ops',
            'short_name' => 'UO',
            'tagline' => 'Operate clearly.',
            'primary_color' => '#112233',
            'secondary_color' => '#445566',
            'company_name' => 'Uplary',
            'website' => 'https://uplary.test',
            'support_email' => 'hello@uplary.test',
            'documentation_url' => 'https://docs.uplary.test',
            'copyright' => 'Uplary',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
            'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('settings', ['tenant_id' => null, 'group' => 'branding', 'key' => 'name', 'value' => 'Uplary Ops']);
        $this->assertDatabaseHas('settings', ['tenant_id' => null, 'group' => 'branding', 'key' => 'primary_color', 'value' => '#112233']);

        foreach (['logo', 'favicon'] as $key) {
            $path = Setting::where(['tenant_id' => null, 'group' => 'branding', 'key' => $key])->value('value');
            $this->assertNotNull($path);
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_tenant_console_applies_color_overrides_on_platform_identity(): void
    {
        [$owner, $tenant] = $this->workspace('owner');
        app(PlatformSettings::class)->put('branding', [
            'name' => 'Uplary Cloud',
            'primary_color' => '#6C4CF5',
            'secondary_color' => '#17152B',
        ]);
        Setting::create(['tenant_id' => $tenant->id, 'group' => 'branding', 'key' => 'name', 'value' => 'Hijacked Brand']);
        Setting::create(['tenant_id' => $tenant->id, 'group' => 'theme', 'key' => 'primary_color', 'value' => '#FF5500']);
        Setting::create(['tenant_id' => $tenant->id, 'group' => 'theme', 'key' => 'secondary_color', 'value' => '#221133']);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Uplary Cloud')
            ->assertDontSee('Hijacked Brand')
            ->assertSee('--primary:#FF5500', false)
            ->assertSee('--secondary:#221133', false);
    }

    public function test_login_uses_platform_colors_not_tenant_overrides(): void
    {
        [$owner, $tenant] = $this->workspace('owner');
        app(PlatformSettings::class)->put('branding', [
            'name' => 'Uplary Cloud',
            'primary_color' => '#6C4CF5',
        ]);
        Setting::create(['tenant_id' => $tenant->id, 'group' => 'theme', 'key' => 'primary_color', 'value' => '#FF5500']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Uplary Cloud')
            ->assertSee('--primary:#6C4CF5', false)
            ->assertDontSee('#FF5500');
    }

    public function test_platform_branding_is_the_default_before_tenant_overrides(): void
    {
        [$owner, $tenant] = $this->workspace('owner');
        app(PlatformSettings::class)->put('branding', [
            'name' => 'Uplary Cloud',
            'primary_color' => '#6C4CF5',
            'secondary_color' => '#17152B',
        ]);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id]);
        app(\App\Support\TenantContext::class)->set($tenant);

        $brand = app(Branding::class)->all();
        $this->assertSame('Uplary Cloud', $brand['name']);
        $this->assertSame('#6C4CF5', $brand['primary_color']);
    }

    private function workspace(string $role): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Reference Workspace']);
        $tenant->users()->attach($user, ['role' => $role, 'is_active' => true]);

        return [$user, $tenant];
    }
}
