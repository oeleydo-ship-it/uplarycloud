<?php

namespace Database\Seeders;

use App\Enums\ServerStatus;
use App\Models\InfrastructureCharge;
use App\Models\InfrastructureOperation;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;
use App\Models\Tenant;
use App\Services\Billing\UsageService;
use App\Services\Infrastructure\InfrastructureBillingService;
use Illuminate\Database\Seeder;

class Phase9DemoSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            ['digitalocean', 's-1vcpu-1gb', 'Basic 1 GB', 1, 1024, 25, 1000, 600, 900, ['nyc3', 'fra1', 'sgp1'], true],
            ['digitalocean', 's-2vcpu-2gb', 'Basic 2 GB', 2, 2048, 60, 2000, 1200, 1700, ['nyc3', 'fra1', 'sgp1'], false],
            ['digitalocean', 's-2vcpu-4gb', 'General 4 GB', 2, 4096, 80, 4000, 2400, 3200, ['nyc3', 'fra1', 'sgp1'], false],
            ['hetzner', 'cx22', 'Shared CX22', 2, 4096, 40, 20000, 450, 850, ['fsn1', 'nbg1', 'hel1'], true],
            ['hetzner', 'cpx21', 'AMD CPX21', 3, 4096, 80, 20000, 850, 1400, ['fsn1', 'nbg1', 'hel1'], false],
            ['hetzner', 'cpx31', 'AMD CPX31', 4, 8192, 160, 20000, 1500, 2300, ['fsn1', 'nbg1', 'hel1'], false],
        ];

        foreach ($catalog as $position => [$provider, $providerPlanId, $name, $cpu, $memory, $disk, $bandwidth, $cost, $price, $regions, $featured]) {
            ManagedServerPlan::updateOrCreate(
                ['provider' => $provider, 'provider_plan_id' => $providerPlanId],
                ['name' => $name, 'category' => 'general', 'cpu_cores' => $cpu, 'memory_mb' => $memory,
                    'disk_gb' => $disk, 'bandwidth_gb' => $bandwidth, 'monthly_cost' => $cost,
                    'monthly_price' => $price, 'currency' => 'USD', 'regions' => $regions,
                    'images' => ['ubuntu-24.04', 'ubuntu-22.04', 'debian-12'], 'featured' => $featured,
                    'active' => true, 'position' => $position + 1]
            );
        }

        $tenant = Tenant::first();
        if (! $tenant) return;
        $owner = $tenant->users()->wherePivot('role', 'owner')->first();
        $plan = ManagedServerPlan::where('provider', 'digitalocean')->where('provider_plan_id', 's-1vcpu-1gb')->firstOrFail();
        $connection = ProviderConnection::updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'DigitalOcean Demo'],
            ['provider' => 'digitalocean', 'api_token' => 'demo-managed-provider-token', 'account_id' => 'demo-team',
                'active' => true, 'last_verified_at' => now(), 'last_error' => null]
        );
        $server = Server::withTrashed()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Managed Edge Server'],
            ['provider_connection_id' => $connection->id, 'managed_server_plan_id' => $plan->id,
                'provider' => 'digitalocean', 'server_type' => 'managed', 'provider_resource_id' => 'managed-demo-edge',
                'provider_region' => 'fra1', 'provider_image' => 'ubuntu-24.04', 'ip_address' => '198.51.100.48',
                'location' => 'fra1', 'operating_system' => 'ubuntu-24.04', 'status' => ServerStatus::Online,
                'ssh_port' => 22, 'ssh_username' => 'root', 'authentication_method' => 'ssh_key',
                'cpu_cores' => $plan->cpu_cores, 'memory_mb' => $plan->memory_mb, 'disk_gb' => $plan->disk_gb,
                'docker_version' => '28.3.3', 'docker_compose_version' => '2.38.2', 'last_seen_at' => now(),
                'provider_created_at' => now()->subDays(9), 'provisioned_at' => now()->subDays(9)]
        );
        if ($server->trashed()) $server->restore();

        $operation = InfrastructureOperation::firstOrCreate(
            ['tenant_id' => $tenant->id, 'server_id' => $server->id, 'idempotency_key' => 'phase9-demo-create-'.$server->id],
            ['requested_by' => $owner?->id, 'action' => 'create', 'status' => 'completed',
                'parameters' => ['region' => 'fra1', 'image' => 'ubuntu-24.04'],
                'provider_response' => ['resource_id' => 'managed-demo-edge', 'status' => 'running'],
                'log' => 'Cloud instance created and Docker provisioning completed.',
                'started_at' => now()->subDays(9), 'completed_at' => now()->subDays(9)->addMinutes(2)]
        );
        app(InfrastructureBillingService::class)->accrue($server->load('managedPlan'), $operation);
        app(UsageService::class)->collect($tenant);
    }
}
