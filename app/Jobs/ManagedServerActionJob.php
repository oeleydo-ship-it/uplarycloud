<?php

namespace App\Jobs;

use App\Models\InfrastructureOperation;
use App\Services\Infrastructure\ManagedInfrastructureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ManagedServerActionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $operationId)
    {
        $this->onQueue(config('infrastructure.queues.infrastructure'));
    }

    public function handle(ManagedInfrastructureService $service): void
    {
        $operation = InfrastructureOperation::findOrFail($this->operationId);

        try {
            $service->perform($operation);
            if ($operation->action === 'rebuild') {
                ProvisionServerJob::dispatch($operation->server->fresh())->delay(now()->addSeconds(45));
            }
        } catch (Throwable $e) {
            $service->fail($operation, $e);
            throw $e;
        }
    }
}
