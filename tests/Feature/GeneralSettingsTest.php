<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_and_update_reference_settings_console(): void
    {
        [$owner, $tenant] = $this->workspace('owner');
        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->get(route('settings'))
            ->assertOk()->assertSee('Platform settings')->assertSee('Platform information')->assertSee('Custom branding');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])->put(route('settings.update'), [
            'name' => 'Acme Cloud', 'platform_url' => 'https://cloud.acme.test', 'timezone' => 'Asia/Dubai',
            'language' => 'en', 'date_format' => 'M j, Y', 'time_format' => 'g:i A', 'maintenance_mode' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('Acme Cloud', $tenant->fresh()->name);
        $this->assertDatabaseHas('settings', ['tenant_id' => $tenant->id, 'group' => 'general', 'key' => 'maintenance_mode', 'value' => '1']);
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $tenant->id, 'action' => 'settings.general.updated']);
    }

    public function test_viewer_cannot_update_general_settings(): void
    {
        [$viewer, $tenant] = $this->workspace('viewer');
        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])->put(route('settings.update'), [])->assertForbidden();
    }

    public function test_owner_can_upload_workspace_brand_assets(): void
    {
        Storage::fake('public');
        [$owner, $tenant] = $this->workspace('owner');
        $payload = [
            'name' => 'Uplary', 'short_name' => 'UP', 'tagline' => '', 'primary_color' => '#6C4CF5',
            'secondary_color' => '#17152B', 'company_name' => '', 'website' => '', 'support_email' => '',
            'documentation_url' => '', 'copyright' => '',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
            'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
        ];

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->put(route('settings.branding.update'), $payload)->assertRedirect()->assertSessionHas('success');

        foreach (['logo', 'favicon'] as $key) {
            $path = Setting::where(['tenant_id' => $tenant->id, 'group' => 'branding', 'key' => $key])->value('value');
            $this->assertNotNull($path);
            Storage::disk('public')->assertExists($path);
        }
    }

    private function workspace(string $role): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Reference Workspace']);
        $tenant->users()->attach($user, ['role' => $role, 'is_active' => true]);
        return [$user, $tenant];
    }
}
