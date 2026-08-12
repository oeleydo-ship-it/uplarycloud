<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_create_and_view_a_ticket(): void
    {
        [$user, $tenant] = $this->workspace();
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post(route('support.store'), [
            'subject' => 'Container restarts during deploy', 'category' => 'deployment', 'priority' => 'high',
            'description' => 'The container restarts repeatedly after the latest deployment completes.',
        ])->assertRedirect()->assertSessionHas('success');

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame($tenant->id, $ticket->tenant_id);
        $this->assertSame($user->id, $ticket->created_by);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get(route('support.show', $ticket))
            ->assertOk()->assertSee('Container restarts during deploy')->assertSee($ticket->number);
    }

    public function test_support_ticket_access_is_tenant_scoped(): void
    {
        [$owner, $tenant] = $this->workspace();
        [$otherOwner, $otherTenant] = $this->workspace();
        $ticket = $tenant->supportTickets()->create([
            'created_by' => $owner->id, 'subject' => 'Private workspace request', 'category' => 'account',
            'priority' => 'normal', 'status' => 'open',
            'description' => 'This ticket must not be visible outside its original workspace.',
        ]);
        $this->actingAs($otherOwner)->withSession(['tenant_id' => $otherTenant->id])
            ->get(route('support.show', $ticket))->assertNotFound();
    }

    public function test_member_can_reply_and_manage_ticket_status(): void
    {
        [$user, $tenant] = $this->workspace();
        $ticket = $tenant->supportTickets()->create([
            'created_by' => $user->id, 'subject' => 'Backup has not completed', 'category' => 'backup',
            'priority' => 'urgent', 'status' => 'open',
            'description' => 'The scheduled backup has remained in progress for more than one hour.',
        ]);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('support.replies.store', $ticket), ['message' => 'The worker is online and no failure appears in the logs.'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('support_ticket_replies', ['support_ticket_id' => $ticket->id, 'user_id' => $user->id]);
        $this->assertSame('waiting', $ticket->fresh()->status);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->put(route('support.status', $ticket), ['status' => 'resolved'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->resolved_at);
    }

    public function test_ticket_links_must_belong_to_the_active_workspace(): void
    {
        [$user, $tenant] = $this->workspace();
        [$otherOwner, $otherTenant] = $this->workspace();
        $server = $otherTenant->servers()->create([
            'created_by' => $otherOwner->id, 'name' => 'Other server', 'hostname' => 'other.example.test',
            'ip_address' => '192.0.2.10', 'ssh_user' => 'root', 'ssh_port' => 22, 'auth_type' => 'password',
            'provider' => 'custom', 'operating_system' => 'ubuntu', 'region' => 'test',
        ]);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post(route('support.store'), [
            'subject' => 'Invalid linked server', 'category' => 'server', 'priority' => 'normal',
            'description' => 'This request attempts to attach a server from another workspace.', 'server_id' => $server->id,
        ])->assertSessionHasErrors('server_id');
    }

    private function workspace(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Support Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner', 'is_active' => true]);
        return [$user, $tenant];
    }
}
