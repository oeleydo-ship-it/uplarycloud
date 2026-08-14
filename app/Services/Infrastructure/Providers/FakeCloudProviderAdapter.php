<?php

namespace App\Services\Infrastructure\Providers;

use App\Contracts\Infrastructure\CloudProviderAdapterInterface;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;

class FakeCloudProviderAdapter implements CloudProviderAdapterInterface
{
    public function verify(ProviderConnection $connection): array
    {
        return ['success' => true, 'account' => $connection->account_id ?: 'demo-account', 'regions' => ['fra1', 'nyc3', 'dub1']];
    }

    public function catalog(ProviderConnection $connection): array
    {
        $plans = $connection->provider === 'hetzner'
            ? $this->hetznerPlans()
            : $this->digitalOceanPlans();

        return ['plans' => $plans];
    }

    /**
     * Curated common DigitalOcean droplet sizes (1 GB–16 GB across basic / CPU / general / memory tiers).
     *
     * @return list<array<string, mixed>>
     */
    private function digitalOceanPlans(): array
    {
        $regions = ['fra1', 'nyc3', 'ams3', 'sgp1', 'lon1'];
        $images = ['ubuntu-24.04', 'ubuntu-22.04', 'debian-12'];

        $sizes = [
            // Basic shared CPU
            ['s-1vcpu-1gb', 'S-1VCPU-1GB · 1 vCPU / 1 GB', 1, 1024, 25, 1000, 600],
            ['s-1vcpu-2gb', 'S-1VCPU-2GB · 1 vCPU / 2 GB', 1, 2048, 50, 2000, 1200],
            ['s-2vcpu-2gb', 'S-2VCPU-2GB · 2 vCPU / 2 GB', 2, 2048, 60, 3000, 1800],
            ['s-2vcpu-4gb', 'S-2VCPU-4GB · 2 vCPU / 4 GB', 2, 4096, 80, 4000, 2400],
            ['s-4vcpu-8gb', 'S-4VCPU-8GB · 4 vCPU / 8 GB', 4, 8192, 160, 5000, 4800],
            ['s-8vcpu-16gb', 'S-8VCPU-16GB · 8 vCPU / 16 GB', 8, 16384, 320, 6000, 9600],
            // CPU-Optimized
            ['c-2', 'C-2 · 2 vCPU / 4 GB', 2, 4096, 25, 4000, 4200],
            ['c-4', 'C-4 · 4 vCPU / 8 GB', 4, 8192, 50, 5000, 8400],
            ['c-8', 'C-8 · 8 vCPU / 16 GB', 8, 16384, 100, 6000, 16800],
            ['c2-4vcpu-8gb', 'C2-4VCPU-8GB · 4 vCPU / 8 GB', 4, 8192, 50, 5000, 9900],
            // General Purpose
            ['g-2vcpu-8gb', 'G-2VCPU-8GB · 2 vCPU / 8 GB', 2, 8192, 25, 4000, 6300],
            ['g-4vcpu-16gb', 'G-4VCPU-16GB · 4 vCPU / 16 GB', 4, 16384, 50, 5000, 12600],
            ['g-2vcpu-8gb-intel', 'G-2VCPU-8GB-INTEL · 2 vCPU / 8 GB', 2, 8192, 30, 4000, 7600],
            // Memory-Optimized
            ['m-2vcpu-16gb', 'M-2VCPU-16GB · 2 vCPU / 16 GB', 2, 16384, 50, 4000, 8400],
            ['m-4vcpu-32gb', 'M-4VCPU-32GB · 4 vCPU / 32 GB', 4, 32768, 100, 6000, 16800],
            ['m3-2vcpu-16gb', 'M3-2VCPU-16GB · 2 vCPU / 16 GB', 2, 16384, 50, 4000, 9900],
            // Storage-Optimized (common entry)
            ['so-2vcpu-16gb', 'SO-2VCPU-16GB · 2 vCPU / 16 GB', 2, 16384, 300, 4000, 13100],
            // Intel basic variants often shown in DO UI
            ['s-1vcpu-1gb-intel', 'S-1VCPU-1GB-INTEL · 1 vCPU / 1 GB', 1, 1024, 25, 1000, 700],
            ['s-2vcpu-4gb-intel', 'S-2VCPU-4GB-INTEL · 2 vCPU / 4 GB', 2, 4096, 80, 4000, 2800],
            ['s-4vcpu-8gb-intel', 'S-4VCPU-8GB-INTEL · 4 vCPU / 8 GB', 4, 8192, 160, 5000, 5600],
            ['s-8vcpu-16gb-intel', 'S-8VCPU-16GB-INTEL · 8 vCPU / 16 GB', 8, 16384, 320, 6000, 11200],
        ];

        return array_map(fn (array $size) => [
            'provider_plan_id' => $size[0],
            'name' => $size[1],
            'cpu_cores' => $size[2],
            'memory_mb' => $size[3],
            'disk_gb' => $size[4],
            'bandwidth_gb' => $size[5],
            'monthly_cost' => $size[6],
            'currency' => 'USD',
            'regions' => $regions,
            'images' => $images,
        ], $sizes);
    }

    /**
     * Curated common Hetzner Cloud x86 server types.
     *
     * @return list<array<string, mixed>>
     */
    private function hetznerPlans(): array
    {
        $regions = ['fsn1', 'nbg1', 'hel1'];
        $images = ['ubuntu-24.04', 'ubuntu-22.04', 'debian-12'];

        $types = [
            ['cx22', 'CX22 · 2 vCPU / 4 GB', 2, 4096, 40, 499],
            ['cx32', 'CX32 · 4 vCPU / 8 GB', 4, 8192, 80, 999],
            ['cx42', 'CX42 · 8 vCPU / 16 GB', 8, 16384, 160, 1899],
            ['cx52', 'CX52 · 16 vCPU / 32 GB', 16, 32768, 320, 3399],
            ['cpx22', 'CPX22 · 2 vCPU / 4 GB', 2, 4096, 80, 699],
            ['cpx32', 'CPX32 · 4 vCPU / 8 GB', 4, 8192, 160, 1299],
            ['cpx42', 'CPX42 · 8 vCPU / 16 GB', 8, 16384, 320, 2499],
            ['cpx52', 'CPX52 · 16 vCPU / 32 GB', 16, 32768, 640, 4599],
            ['cax11', 'CAX11 · 2 vCPU / 4 GB', 2, 4096, 40, 399],
            ['cax21', 'CAX21 · 4 vCPU / 8 GB', 4, 8192, 80, 799],
            ['cax31', 'CAX31 · 8 vCPU / 16 GB', 8, 16384, 160, 1499],
            ['ccx13', 'CCX13 · 2 vCPU / 8 GB', 2, 8192, 80, 1299],
            ['ccx23', 'CCX23 · 4 vCPU / 16 GB', 4, 16384, 160, 2499],
            ['ccx33', 'CCX33 · 8 vCPU / 32 GB', 8, 32768, 240, 4899],
        ];

        return array_map(fn (array $type) => [
            'provider_plan_id' => $type[0],
            'name' => $type[1],
            'cpu_cores' => $type[2],
            'memory_mb' => $type[3],
            'disk_gb' => $type[4],
            'bandwidth_gb' => 20000,
            'monthly_cost' => $type[5],
            'currency' => 'EUR',
            'regions' => $regions,
            'images' => $images,
        ], $types);
    }

    public function create(Server $server, ManagedServerPlan $plan, array $options): array
    {
        return [
            'resource_id' => 'managed-'.strtolower(str_replace('-', '', substr($server->uuid, 0, 13))),
            'ip_address' => '198.51.100.'.(20 + ($server->id % 180)),
            'status' => 'running',
            'region' => $options['region'],
            'image' => $options['image'],
        ];
    }

    public function status(Server $server): array
    {
        return ['resource_id' => $server->provider_resource_id, 'status' => 'running', 'ip_address' => $server->ip_address];
    }

    public function restart(Server $server): array
    {
        return ['resource_id' => $server->provider_resource_id, 'status' => 'restarting'];
    }

    public function powerOff(Server $server): array
    {
        return ['resource_id' => $server->provider_resource_id, 'status' => 'powered_off'];
    }

    public function resize(Server $server, ManagedServerPlan $plan): array
    {
        return ['resource_id' => $server->provider_resource_id, 'status' => 'resizing', 'plan' => $plan->provider_plan_id];
    }

    public function rebuild(Server $server, string $image): array
    {
        return ['resource_id' => $server->provider_resource_id, 'status' => 'rebuilding', 'image' => $image];
    }

    public function destroy(Server $server): array
    {
        return ['resource_id' => $server->provider_resource_id, 'status' => 'deleted'];
    }

    public function destroyWithAssociatedResources(Server $server): array
    {
        return ['resource_id' => $server->provider_resource_id, 'status' => 'deleted', 'associated_resources' => 'deleted'];
    }
}
