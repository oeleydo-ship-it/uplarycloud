<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerPowerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ServerPowerActionJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 300;

    public function __construct(public Server $server, public string $action, public ?int $userId = null)
    {
        $this->onQueue(config('infrastructure.queues.infrastructure'));
    }

    public function uniqueId(): string
    {
        return 'server-power-'.$this->server->getKey().'-'.$this->action;
    }

    public function handle(ServerPowerService $power): void
    {
        $server = $this->server->fresh();

        match ($this->action) {
            'shutdown' => $power->shutdown($server, $this->userId),
            'reboot' => $power->reboot($server, $this->userId),
            'restore' => $power->restore($server, $this->userId),
            default => throw new RuntimeException('Unsupported server power action.'),
        };
    }

    public function failed(Throwable $exception): void
    {
        $this->server->fresh()?->update([
            'failure_reason' => str($exception->getMessage())->limit(500)->toString(),
        ]);
    }
}
