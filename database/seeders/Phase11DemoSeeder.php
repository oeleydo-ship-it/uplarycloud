<?php

namespace Database\Seeders;

use App\Models\ApplicationDeployment;
use App\Models\Server;
use App\Models\SupportTicket;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class Phase11DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();
        $user = $tenant?->users()->first();
        if (! $tenant || ! $user) return;

        $server = Server::where('tenant_id', $tenant->id)->first();
        $deployment = ApplicationDeployment::where('tenant_id', $tenant->id)->first();
        $ticket = SupportTicket::firstOrCreate(['number' => 'SUP-DEMO-1001'], [
            'tenant_id' => $tenant->id, 'created_by' => $user->id, 'server_id' => $server?->id,
            'application_deployment_id' => $deployment?->id, 'subject' => 'Deployment health check is timing out',
            'category' => 'deployment', 'priority' => 'high', 'status' => 'waiting',
            'description' => 'The application deploys successfully, but its health check times out after the container starts. The runtime logs do not show an application error.',
            'last_replied_at' => now()->subMinutes(42),
        ]);
        $ticket->replies()->firstOrCreate(
            ['message' => 'We found that the application listens on a different internal port than the configured health check. Please confirm the container port in deployment settings.'],
            ['user_id' => null, 'staff_reply' => true]
        );
        SupportTicket::firstOrCreate(['number' => 'SUP-DEMO-1002'], [
            'tenant_id' => $tenant->id, 'created_by' => $user->id, 'server_id' => $server?->id,
            'subject' => 'Review backup retention policy', 'category' => 'backup', 'priority' => 'normal', 'status' => 'open',
            'description' => 'Please help us confirm that the current backup schedule retains seven daily recovery points for this server.',
        ]);
        SupportTicket::firstOrCreate(['number' => 'SUP-DEMO-1003'], [
            'tenant_id' => $tenant->id, 'created_by' => $user->id, 'subject' => 'Custom domain verification completed',
            'category' => 'domain', 'priority' => 'low', 'status' => 'resolved',
            'description' => 'DNS records are now resolving correctly and the managed certificate has been issued.',
            'resolved_at' => now()->subDay(),
        ]);
    }
}
