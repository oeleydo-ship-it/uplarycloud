<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerProvisioningUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public function __construct(public Server $server, public string $step, public string $status, public string $message) {}
    public function broadcastOn(): array { return [new PrivateChannel('tenants.'.$this->server->tenant_id.'.servers.'.$this->server->uuid)]; }
    public function broadcastAs(): string { return 'server.provisioning.updated'; }
    public function broadcastWith(): array { return ['server' => $this->server->uuid, 'step' => $this->step, 'status' => $this->status, 'message' => $this->message]; }
}
