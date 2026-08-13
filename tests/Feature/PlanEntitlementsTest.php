<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCategory;
use App\Models\ApplicationDeployment;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gated_feature_is_denied_and_hidden_from_nav(): void
    {
        [$owner, $tenant] = $this->workspace([
            'monitoring' => false,
            'backups' => false,
            'api_tokens' => false,
            'git_deploy' => false,
        ], ['servers' => 5, 'applications' => 5]);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('monitoring.index'))
            ->assertOk()
            ->assertSee('is not on your plan')
            ->assertSee('View plans');

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('<span>Monitoring</span>', false)
            ->assertDontSee('<span>API Tokens</span>', false);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('applications.web.create'))
            ->assertOk()
            ->assertSee('Git deploy is not on your plan');
    }

    public function test_application_quota_blocks_additional_deploys(): void
    {
        [$owner, $tenant, $server] = $this->workspaceWithServer([
            'marketplace' => true,
            'custom_docker' => true,
        ], ['applications' => 1, 'containers' => 50, 'volumes' => 50, 'servers' => 5]);

        $app = $this->catalogApp();
        ApplicationDeployment::create([
            'tenant_id' => $tenant->id,
            'application_id' => $app->id,
            'server_id' => $server->id,
            'created_by' => $owner->id,
            'name' => 'Existing App',
            'deployment_type' => 'marketplace',
            'docker_image' => $app->docker_image,
            'docker_tag' => 'latest',
            'restart_policy' => 'unless-stopped',
        ]);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->post(route('deployments.store'), [
                'application_id' => $app->id,
                'deployment_type' => 'marketplace',
                'server_id' => $server->id,
                'name' => 'Second App',
                'docker_image' => $app->docker_image,
                'docker_tag' => 'latest',
                'container_port' => 3000,
                'restart_policy' => 'unless-stopped',
            ])
            ->assertSessionHasErrors('applications');
        $this->assertStringContainsString('Upgrade', session('errors')->first('applications'));

        $this->assertDatabaseMissing('application_deployments', ['name' => 'Second App']);

        $this->actingAs($owner)->withSession(['tenant_id' => $tenant->id])
            ->get(route('applications.index'))
            ->assertOk()
            ->assertSee('Plan limit reached');
    }

    public function test_superadmin_can_edit_plan_gates_and_quotas(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $defaults = PlanCatalog::defaultsFor('starter');

        $this->actingAs($admin)->post(route('admin.plans.store'), [
            'name' => 'Growth',
            'slug' => 'growth',
            'description' => 'Editable plan',
            'monthly_price' => 19,
            'yearly_price' => 190,
            'currency' => 'USD',
            'servers' => 4,
            'applications' => 6,
            'domains' => '',
            'team_members' => 8,
            'features' => 'Email support',
            'gate_marketplace' => 1,
            'gate_git_deploy' => 1,
            'gate_backups' => 1,
            'active' => 1,
        ])->assertSessionHasNoErrors();

        $plan = Plan::where('slug', 'growth')->firstOrFail();
        $this->assertTrue($plan->allowsFeature('marketplace'));
        $this->assertTrue($plan->allowsFeature('git_deploy'));
        $this->assertTrue($plan->allowsFeature('backups'));
        $this->assertFalse($plan->allowsFeature('sla_support'));
        $this->assertSame(4.0, $plan->limit('servers'));
        $this->assertSame(6.0, $plan->limit('applications'));
        $this->assertNull($plan->limit('domains'));
        $this->assertSame(8.0, $plan->limit('team_members'));
        $this->assertContains('Email support', $plan->features);
        $this->assertSame(array_keys($defaults['gates']), array_keys($plan->gates));

        $this->actingAs($admin)->get(route('admin.plans'))
            ->assertOk()
            ->assertSee('Gated features')
            ->assertSee('Quotas')
            ->assertSee('Growth');
    }

    private function workspace(array $gates, array $limits): array
    {
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $plan = Plan::create([
            'name' => 'Restricted',
            'slug' => 'restricted-'.fake()->unique()->numerify('###'),
            'description' => 'Test plan',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'currency' => 'USD',
            'limits' => $limits,
            'gates' => $gates,
            'features' => [],
            'active' => true,
        ]);
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'monthly']);

        return [$owner, $tenant];
    }

    private function workspaceWithServer(array $gates, array $limits): array
    {
        [$owner, $tenant] = $this->workspace($gates, $limits);
        $server = Server::create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'provider' => 'custom',
            'ip_address' => fake()->unique()->ipv4(),
            'operating_system' => 'ubuntu-24.04',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
            'cpu_cores' => 4,
            'memory_mb' => 8192,
            'disk_gb' => 160,
        ]);

        return [$owner, $tenant, $server];
    }

    private function catalogApp(): Application
    {
        $category = ApplicationCategory::create(['name' => 'Analytics', 'slug' => 'analytics-'.fake()->unique()->numerify('##'), 'position' => 1]);

        return Application::create([
            'category_id' => $category->id,
            'name' => 'Uptime Pulse',
            'slug' => 'uptime-pulse-'.fake()->unique()->numerify('##'),
            'description' => 'Monitoring dashboard',
            'docker_image' => 'example/uptime-pulse',
            'active' => true,
        ]);
    }
}
