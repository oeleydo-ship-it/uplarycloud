<?php

namespace App\Contracts\Infrastructure;

use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;

interface CloudProviderAdapterInterface
{
    public function verify(ProviderConnection $connection): array;

    /**
     * @return array{plans: list<array{provider_plan_id: string, name: string, cpu_cores: int, memory_mb: int, disk_gb: int, bandwidth_gb: int, monthly_cost: int, currency: string, regions: list<string>, images: list<string>}>}
     */
    public function catalog(ProviderConnection $connection): array;

    public function create(Server $server, ManagedServerPlan $plan, array $options): array;

    public function status(Server $server): array;

    public function restart(Server $server): array;

    public function powerOff(Server $server): array;

    public function resize(Server $server, ManagedServerPlan $plan): array;

    public function rebuild(Server $server, string $image): array;

    public function destroy(Server $server): array;

    /**
     * Permanently destroy the instance and provider resources attached to it.
     * This is reserved for an explicitly confirmed destructive user action.
     */
    public function destroyWithAssociatedResources(Server $server): array;
}
