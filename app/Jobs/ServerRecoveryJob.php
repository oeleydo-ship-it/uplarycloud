<?php

namespace App\Jobs;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Servers\ServerMaintenanceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ServerRecoveryJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public int $serverId)
    {
        $this->onQueue(config('infrastructure.queues.infrastructure'));
    }

    public function uniqueId(): string
    {
        return 'server-recovery-'.$this->serverId;
    }

    public function handle(ServerExecutorInterface $executor, ServerMaintenanceService $maintenance): void
    {
        $server = Server::find($this->serverId);
        if ($server === null || $server->status !== ServerStatus::Maintenance) {
            return;
        }

        $deadline = now()->addMinutes(10);

        while (now()->lt($deadline)) {
            try {
                $executor->ensureReady($server);
                $executor->execute($server, 'docker info >/dev/null 2>&1 && echo READY', 60);
                break;
            } catch (Throwable $exception) {
                Log::info('Waiting for server to finish restarting', [
                    'server_id' => $server->id,
                    'message' => $exception->getMessage(),
                ]);
                sleep(10);
            }
        }

        if (now()->gte($deadline)) {
            $server->update([
                'status' => ServerStatus::Online,
                'failure_reason' => 'The server did not respond over SSH after restarting. Check the host and try again.',
                'last_seen_at' => now(),
            ]);

            return;
        }

        try {
            $maintenance->disable($server);
        } catch (Throwable $exception) {
            Log::warning('Could not remove maintenance page after restart', [
                'server_id' => $server->id,
                'message' => $exception->getMessage(),
            ]);
        }

        $server->update([
            'status' => ServerStatus::Online,
            'failure_reason' => null,
            'last_seen_at' => now(),
        ]);
    }
}
