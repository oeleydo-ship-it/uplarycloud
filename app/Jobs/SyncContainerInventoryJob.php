<?php

namespace App\Jobs;

use App\Events\DockerResourceUpdated;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Services\Docker\ContainerInventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncContainerInventoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public array $backoff = [10, 30, 90];

    public function __construct(
        public int $tenantId,
        public ?int $serverId = null,
        public ?int $userId = null,
    ) {
        $this->onQueue(config('infrastructure.queues.deployments'));
    }

    public function handle(ContainerInventoryService $inventory): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        $updated = $inventory->syncTenant($tenant, $this->serverId);

        CollectOperationsMetricsJob::dispatch($this->serverId);
        ActivityLog::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'action' => 'docker.containers.sync',
            'description' => "Container inventory sync completed ({$updated} refreshed)",
            'created_at' => now(),
        ]);
        event(new DockerResourceUpdated($this->tenantId, 'container', 'inventory', 'sync', 'completed'));
    }

    public function failed(Throwable $exception): void
    {
        event(new DockerResourceUpdated($this->tenantId, 'container', 'inventory', 'sync', 'failed'));
    }
}
