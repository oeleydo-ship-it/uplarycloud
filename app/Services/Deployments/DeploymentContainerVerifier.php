<?php

namespace App\Services\Deployments;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Models\Server;
use App\Support\RemoteShell;
use RuntimeException;
use Throwable;

class DeploymentContainerVerifier
{
    public function __construct(private readonly ServerExecutorInterface $executor) {}

    /**
     * Confirm the named container is running on the host (and healthy when a Docker healthcheck exists).
     */
    public function assertRunning(Server $server, string $containerName, int $timeoutSeconds = 90): void
    {
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        $lastStatus = null;
        $lastHealth = null;

        do {
            try {
                $status = strtolower(trim($this->executor->execute(
                    $server,
                    'docker inspect --format '.RemoteShell::quote('{{.State.Status}}').' '.RemoteShell::quote($containerName)
                )));
            } catch (Throwable $exception) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException(
                        'Container '.$containerName.' was not found on the server: '.$exception->getMessage(),
                        0,
                        $exception
                    );
                }
                usleep(1_500_000);

                continue;
            }

            $lastStatus = $status;

            if (in_array($status, ['exited', 'dead', 'removing', 'paused'], true)) {
                throw new RuntimeException(
                    'Container '.$containerName.' is '.$status.' instead of running on the server.'
                );
            }

            if ($status === 'running') {
                $health = strtolower(trim($this->executor->execute(
                    $server,
                    'docker inspect --format '.RemoteShell::quote('{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}').' '.RemoteShell::quote($containerName)
                )));
                $lastHealth = $health;

                if (in_array($health, ['none', 'healthy', ''], true)) {
                    return;
                }

                if ($health === 'unhealthy') {
                    throw new RuntimeException('Container '.$containerName.' is running but unhealthy on the server.');
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(1_500_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            'Container '.$containerName.' did not become ready on the server in time (status='.($lastStatus ?? 'unknown').', health='.($lastHealth ?? 'n/a').').'
        );
    }

    /**
     * @return array{ok: bool, status: ?string, health: ?string, message: string}
     */
    public function inspect(Server $server, string $containerName): array
    {
        try {
            $status = strtolower(trim($this->executor->execute(
                $server,
                'docker inspect --format '.RemoteShell::quote('{{.State.Status}}').' '.RemoteShell::quote($containerName)
            )));
            $health = strtolower(trim($this->executor->execute(
                $server,
                'docker inspect --format '.RemoteShell::quote('{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}').' '.RemoteShell::quote($containerName)
            )));
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'status' => null,
                'health' => null,
                'message' => 'Container '.$containerName.' was not found on the server: '.$exception->getMessage(),
            ];
        }

        if ($status !== 'running') {
            return [
                'ok' => false,
                'status' => $status,
                'health' => $health,
                'message' => 'Container '.$containerName.' is '.$status.' on the server (expected running).',
            ];
        }

        if ($health === 'unhealthy') {
            return [
                'ok' => false,
                'status' => $status,
                'health' => $health,
                'message' => 'Container '.$containerName.' is unhealthy on the server.',
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'health' => $health === 'none' ? null : $health,
            'message' => 'Container '.$containerName.' is running on the server.',
        ];
    }
}
