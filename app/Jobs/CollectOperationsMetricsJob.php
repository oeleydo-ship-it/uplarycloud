<?php
namespace App\Jobs;
use App\Models\Server;use App\Services\Operations\MonitoringService;use Illuminate\Contracts\Queue\ShouldQueue;use Illuminate\Foundation\Queue\Queueable;
class CollectOperationsMetricsJob implements ShouldQueue{use Queueable;public int $timeout=300;public function __construct(public ?int $serverId=null){$this->onQueue(config('infrastructure.queues.monitoring'));}public function handle(MonitoringService $monitoring):void{$query=Server::where('status','online');if($this->serverId)$query->whereKey($this->serverId);$query->each(fn($server)=>$monitoring->collect($server));}}
