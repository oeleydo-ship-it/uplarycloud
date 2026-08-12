<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_endpoint_is_public_and_secured(): void
    {
        $this->getJson(route('health.live'))
            ->assertOk()
            ->assertJsonPath('status', 'alive')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_readiness_endpoint_reports_core_dependencies(): void
    {
        $this->getJson(route('health.ready'))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.application_key.status', 'pass')
            ->assertJsonPath('checks.database.status', 'pass')
            ->assertJsonPath('checks.migrations.status', 'pass')
            ->assertJsonPath('checks.cache.status', 'pass')
            ->assertJsonPath('checks.storage.status', 'pass');
    }

    public function test_system_health_console_requires_a_workspace_member(): void
    {
        $this->get(route('system-health'))->assertRedirect(route('login'));

        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Operations Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('system-health'))
            ->assertOk()
            ->assertSee('Readiness checks')
            ->assertSee('/health/live')
            ->assertSee('/health/ready');
    }

    public function test_platform_doctor_uses_the_same_readiness_report(): void
    {
        $this->assertSame(0, Artisan::call('platform:doctor', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ready', $report['status']);
        $this->assertSame('pass', $report['checks']['database']['status']);
    }
}
