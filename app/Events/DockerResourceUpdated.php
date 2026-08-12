<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DockerResourceUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    public function __construct(public int $tenantId, public string $type, public string $uuid, public string $action, public string $status) {}
    public function broadcastOn(): array { return [new PrivateChannel('tenants.'.$this->tenantId.'.docker')]; }
    public function broadcastAs(): string { return 'docker.resource.updated'; }
}
