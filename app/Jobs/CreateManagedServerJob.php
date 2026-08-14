<?php

namespace App\Jobs;

use App\Models\InfrastructureOperation;
use App\Services\Infrastructure\ManagedInfrastructureService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CreateManagedServerJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public array $backoff = [15, 60, 180];

    public int $uniqueFor = 900;

    public function __construct(public int $operationId)
    {
        $this->onQueue(config('infrastructure.queues.infrastructure'));
    }

    public function uniqueId(): string
    {
        return 'create-managed-server-'.$this->operationId;
    }

    public function handle(ManagedInfrastructureService $service): void
    {
        $operation = InfrastructureOperation::with(['server' => fn ($query) => $query->withTrashed()])->findOrFail($this->operationId);
        $server = $operation->server;

        if ($server === null || $server->trashed()) {
            return;
        }

        try {
            $service->create($operation);
            ProvisionServerJob::dispatch($operation->server->fresh())
                ->delay(now()->addSeconds(45));
        } catch (Throwable $e) {
            $service->fail($operation, $e);
            throw $e;
        }
    }
}
