<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DomainStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $tenantId, public string $domainUuid, public string $status, public string $dnsStatus, public string $sslStatus) {}
    public function broadcastOn(): array { return [new PrivateChannel('tenants.'.$this->tenantId.'.domains')]; }
    public function broadcastAs(): string { return 'domain.status.changed'; }
}
