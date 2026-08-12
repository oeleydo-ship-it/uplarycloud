<?php

namespace Tests\Feature;

use App\Jobs\CalculateUsageJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Billing\PlanLimitService;
use App\Services\Billing\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_activate_plan_and_receives_paid_invoice(): void
    {
        [$owner,$tenant]=$this->workspace();$pro=$this->plan('pro',2900,['servers'=>10,'team_members'=>10,'backup_storage_gb'=>500]);
        $this->actingAs($owner)->withSession(['tenant_id'=>$tenant->id])->post(route('billing.subscribe'),['plan_id'=>$pro->id,'billing_cycle'=>'monthly'])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('subscriptions',['tenant_id'=>$tenant->id,'plan_id'=>$pro->id,'status'=>'active']);$this->assertDatabaseHas('billing_invoices',['tenant_id'=>$tenant->id,'status'=>'paid','total'=>2900]);$this->assertDatabaseHas('payment_methods',['tenant_id'=>$tenant->id,'last_four'=>'4242']);
    }

    public function test_billing_member_can_view_billing_but_developer_cannot(): void
    {
        [$owner,$tenant]=$this->workspace();$billing=User::factory()->create();$developer=User::factory()->create();$tenant->users()->attach($billing,['role'=>'billing']);$tenant->users()->attach($developer,['role'=>'developer']);
        $this->actingAs($billing)->withSession(['tenant_id'=>$tenant->id])->get(route('billing.index'))->assertOk();$this->actingAs($developer)->withSession(['tenant_id'=>$tenant->id])->get(route('billing.index'))->assertForbidden();
    }

    public function test_sanctum_token_is_tenant_bound_scoped_and_hashed(): void
    {
        [$owner,$tenant]=$this->workspace();$this->server($tenant);$response=$this->actingAs($owner)->withSession(['tenant_id'=>$tenant->id])->post(route('api-tokens.store'),['name'=>'Deploy bot','scopes'=>['servers:read'],'environment'=>'production','expires_in'=>'90']);$response->assertRedirect();$plain=session('plain_api_token');$this->assertNotEmpty($plain);$this->assertDatabaseMissing('personal_access_tokens',['token'=>$plain]);
        auth()->logout();$this->withToken($plain)->getJson('/api/v1/servers')->assertOk()->assertJsonCount(1,'data');
    }

    public function test_token_scope_and_ip_restrictions_are_enforced(): void
    {
        [$owner,$tenant]=$this->workspace();$created=$owner->createToken('restricted',['applications:read'],now()->addDay());$created->accessToken->update(['tenant_id'=>$tenant->id,'environment'=>'production','ip_restrictions'=>['203.0.113.10']]);
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.11'])->withToken($created->plainTextToken)->getJson('/api/v1/servers')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.10'])->withToken($created->plainTextToken)->getJson('/api/v1/servers')->assertForbidden();
    }

    public function test_api_token_console_filters_updates_and_retains_revoked_tokens(): void
    {
        [$owner,$tenant]=$this->workspace();
        $active=$owner->createToken('Deploy bot',['servers:read'],now()->addDays(90))->accessToken;$active->update(['tenant_id'=>$tenant->id,'environment'=>'production']);
        $expired=$owner->createToken('Old monitor',['monitoring:read'],now()->subDay())->accessToken;$expired->update(['tenant_id'=>$tenant->id,'environment'=>'staging']);
        $this->actingAs($owner)->withSession(['tenant_id'=>$tenant->id])->get(route('api-tokens.index',['status'=>'expired']))->assertOk()->assertSee('Old monitor')->assertDontSee('Deploy bot');
        $this->actingAs($owner)->withSession(['tenant_id'=>$tenant->id])->put(route('api-tokens.update',$active),['name'=>'CI Deployment','scopes'=>['servers:read','deployments:write'],'environment'=>'staging','expires_in'=>'365','ip_restrictions'=>'203.0.113.10'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(['203.0.113.10'],$active->fresh()->ip_restrictions);
        $this->actingAs($owner)->withSession(['tenant_id'=>$tenant->id])->delete(route('api-tokens.destroy',$active))->assertRedirect();
        $this->assertNotNull($active->fresh()->revoked_at);$this->assertDatabaseHas('personal_access_tokens',['id'=>$active->id]);
    }

    public function test_admin_can_invite_member_and_invited_user_can_accept(): void
    {
        Notification::fake();[$owner,$tenant]=$this->workspace();$this->plan('free',0,['servers'=>1,'team_members'=>3,'backup_storage_gb'=>1]);$invited=User::factory()->create(['email'=>'new@example.com']);
        $response=$this->actingAs($owner)->withSession(['tenant_id'=>$tenant->id])->post(route('team.invite'),['email'=>$invited->email,'role'=>'developer']);$response->assertRedirect();Notification::assertSentOnDemand(TeamInvitationNotification::class);$url=$response->getSession()->get('invitation_url');$invitation=$tenant->invitations()->firstOrFail();$token=basename($url);
        $this->actingAs($invited)->get(route('invitations.accept',[$invitation,$token]))->assertRedirect(route('dashboard'));$this->assertDatabaseHas('tenant_user',['tenant_id'=>$tenant->id,'user_id'=>$invited->id,'role'=>'developer','is_active'=>true]);
    }

    public function test_plan_limits_are_enforced_before_resource_creation(): void
    {
        [$owner,$tenant]=$this->workspace();$free=$this->plan('free',0,['servers'=>1,'team_members'=>1,'backup_storage_gb'=>1]);$tenant->subscriptions()->create(['plan_id'=>$free->id,'status'=>'active','billing_cycle'=>'monthly']);$this->server($tenant);
        $this->expectException(ValidationException::class);app(PlanLimitService::class)->enforce($tenant,'servers');
    }

    public function test_usage_job_records_tenant_metrics(): void
    {
        [$owner,$tenant]=$this->workspace();$this->server($tenant);(new CalculateUsageJob($tenant->id))->handle(app(UsageService::class));$this->assertDatabaseHas('usage_records',['tenant_id'=>$tenant->id,'metric'=>'servers','quantity'=>1]);$this->assertDatabaseHas('usage_records',['tenant_id'=>$tenant->id,'metric'=>'team_members','quantity'=>1]);
    }

    private function workspace(): array{$owner=User::factory()->create();$tenant=Tenant::create(['name'=>fake()->unique()->company()]);$tenant->users()->attach($owner,['role'=>'owner','is_active'=>true]);return[$owner,$tenant];}
    private function plan(string $slug,int $price,array $limits):Plan{return Plan::create(['name'=>ucfirst($slug),'slug'=>$slug,'description'=>'Test plan','monthly_price'=>$price,'yearly_price'=>$price*10,'currency'=>'USD','limits'=>$limits,'features'=>['API access'],'active'=>true]);}
    private function server(Tenant $tenant):Server{return Server::create(['tenant_id'=>$tenant->id,'name'=>'Production','provider'=>'custom','ip_address'=>fake()->unique()->ipv4(),'operating_system'=>'ubuntu-24.04','status'=>'online','authentication_method'=>'ssh_key']);}
}
