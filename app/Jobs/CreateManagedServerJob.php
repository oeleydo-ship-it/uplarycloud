<?php
namespace App\Jobs;
use App\Models\InfrastructureOperation;use App\Services\Infrastructure\ManagedInfrastructureService;use Illuminate\Contracts\Queue\ShouldQueue;use Illuminate\Foundation\Queue\Queueable;use Throwable;
class CreateManagedServerJob implements ShouldQueue{use Queueable;public int $tries=3;public int $timeout=900;public array $backoff=[15,60,180];public function __construct(public int $operationId){$this->onQueue(config('infrastructure.queues.infrastructure'));}public function handle(ManagedInfrastructureService $service):void{$operation=InfrastructureOperation::findOrFail($this->operationId);try{$service->create($operation);ProvisionServerJob::dispatch($operation->server)->delay(now()->addSeconds(20));}catch(Throwable $e){$service->fail($operation,$e);throw$e;}}}
