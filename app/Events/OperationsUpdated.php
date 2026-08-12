<?php
namespace App\Events;
use Illuminate\Broadcasting\PrivateChannel;use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;use Illuminate\Foundation\Events\Dispatchable;
class OperationsUpdated implements ShouldBroadcastNow{use Dispatchable;public function __construct(public int $tenantId,public string $area,public string $status,public ?string $resourceUuid=null){}public function broadcastOn():array{return[new PrivateChannel('tenants.'.$this->tenantId.'.operations')];}public function broadcastAs():string{return'operations.updated';}}
