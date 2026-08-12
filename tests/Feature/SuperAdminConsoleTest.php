<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmins_can_access_platform_console(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $admin=User::factory()->create(['is_super_admin'=>true]);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Platform Overview');
    }

    public function test_superadmin_can_persist_global_settings_and_encrypted_gateway_secrets(): void
    {
        $admin=User::factory()->create(['is_super_admin'=>true]);
        $this->actingAs($admin)->put(route('admin.settings.update'),['platform_name'=>'Uplary','platform_url'=>'https://uplary.test','support_email'=>'support@uplary.test','default_timezone'=>'UTC','default_currency'=>'USD','registration_enabled'=>1])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.payments.update'),['billing_driver'=>'stripe','stripe_public_key'=>'pk_test','stripe_secret'=>'sk_secret','stripe_webhook_secret'=>'whsec_secret','paypal_mode'=>'sandbox','tax_percentage'=>5])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('settings',['tenant_id'=>null,'group'=>'general','key'=>'platform_name','value'=>'Uplary']);
        $secret=Setting::whereNull('tenant_id')->where('group','payments')->where('key','stripe_secret')->firstOrFail();
        $this->assertTrue($secret->is_encrypted); $this->assertNotSame('sk_secret',$secret->value);
    }

    public function test_superadmin_can_create_and_update_subscription_plan(): void
    {
        $admin=User::factory()->create(['is_super_admin'=>true]);
        $response=$this->actingAs($admin)->post(route('admin.plans.store'),['name'=>'Scale','slug'=>'scale','monthly_price'=>99,'yearly_price'=>999,'currency'=>'USD','servers'=>20,'team_members'=>50,'backup_storage_gb'=>1000,'features'=>"Priority support\nSSO",'active'=>1]);
        $response->assertSessionHasNoErrors(); $plan=Plan::where('slug','scale')->firstOrFail();
        $this->assertSame(9900,$plan->monthly_price); $this->assertSame(20.0,$plan->limit('servers')); $this->assertContains('SSO',$plan->features);
    }

    public function test_superadmin_controls_cloud_connection_and_markup_pricing(): void
    {
        config()->set('infrastructure.managed_driver', 'fake');
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->get(route('admin.cloud'))->assertOk()->assertSee('Managed Cloud');
        $this->actingAs($admin)->post(route('admin.cloud.connections.store'), [
            'name' => 'Platform DigitalOcean', 'provider' => 'digitalocean', 'api_token' => 'secret', 'active' => 1,
        ])->assertSessionHasNoErrors();
        $connection = ProviderConnection::where('platform_managed', true)->firstOrFail();
        $this->assertTrue($connection->active);
        $this->assertNotNull($connection->last_verified_at);
        $this->actingAs($admin)->post(route('admin.cloud.plans.store'), [
            'provider' => 'digitalocean', 'provider_plan_id' => 's-2vcpu-2gb', 'name' => 'Cloud 2 GB',
            'cpu_cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 50, 'bandwidth_gb' => 1000,
            'monthly_cost' => 10, 'markup_percentage' => 35, 'currency' => 'USD',
            'regions' => 'fra1, nyc3', 'images' => 'ubuntu-24.04', 'active' => 1,
        ])->assertSessionHasNoErrors();
        $plan = ManagedServerPlan::where('provider_plan_id', 's-2vcpu-2gb')->firstOrFail();
        $this->assertSame(1000, $plan->monthly_cost);
        $this->assertSame(1350, $plan->monthly_price);
        $second = ManagedServerPlan::create(['provider'=>'hetzner','provider_plan_id'=>'cx22','name'=>'CX22','cpu_cores'=>2,'memory_mb'=>4096,'disk_gb'=>40,'bandwidth_gb'=>1000,'monthly_cost'=>500,'markup_percentage'=>20,'monthly_price'=>600,'currency'=>'USD','regions'=>['fsn1'],'images'=>['ubuntu-24.04'],'active'=>true]);
        $this->actingAs($admin)->put(route('admin.cloud.markup.update'), ['markup_percentage'=>100])->assertSessionHasNoErrors();
        $this->assertSame(2000, $plan->fresh()->monthly_price);
        $this->assertSame(1000, $second->fresh()->monthly_price);
    }
}
