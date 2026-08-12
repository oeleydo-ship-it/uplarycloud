<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DeploymentProgressed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $tenantId,
        public string $deploymentUuid,
        public string $status,
        public int $progress,
        public string $stage,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenants.'.$this->tenantId.'.deployments')];
    }

    public function broadcastAs(): string
    {
        return 'deployment.progressed';
    }

    public function broadcastWith(): array
    {
        return [
            'deploymentUuid' => $this->deploymentUuid,
            'status' => $this->status,
            'progress' => $this->progress,
            'stage' => $this->stage,
        ];
    }
}
