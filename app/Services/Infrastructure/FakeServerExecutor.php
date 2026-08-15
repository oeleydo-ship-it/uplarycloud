<?php

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Models\Server;

class FakeServerExecutor implements ServerExecutorInterface
{
    public function test(Server $server): array
    {
        $server->loadMissing('credential');
        $privateKey = (string) ($server->credential?->private_key ?? '');
        $password = (string) ($server->credential?->password ?? '');

        if ($privateKey === 'invalid-connection-key' || $password === 'invalid-connection-password') {
            return [
                'success' => false,
                'message' => 'SSH authentication failed.',
                'system' => [],
            ];
        }

        if ($privateKey === 'low-memory-key') {
            return [
                'success' => true,
                'message' => 'SSH authentication succeeded, but the host does not meet resource requirements.',
                'system' => [
                    'operating_system' => $server->operating_system,
                    'cpu_cores' => 2,
                    'memory_mb' => 512,
                    'disk_gb' => 160,
                    'docker_available' => false,
                ],
            ];
        }

        return [
            'success' => true,
            'message' => 'Simulated connection verified (INFRASTRUCTURE_DRIVER=fake).',
            'system' => [
                'operating_system' => $server->operating_system,
                'cpu_cores' => 4,
                'memory_mb' => 8192,
                'disk_gb' => 160,
                'docker_available' => false,
                'simulated' => true,
            ],
        ];
    }

    public function ensureReady(Server $server): void
    {
    }

    public function execute(Server $server, string $command, ?int $timeoutSeconds = null): string
    {
        return match (true) {
            str_contains($command, 'docker version --format') => '28.3.3',
            str_contains($command, 'docker compose version --short') => '2.38.2',
            str_contains($command, 'echo READY') => 'READY',
            str_contains($command, '{{.State.Status}}') => $server->name === 'Unhealthy Host' ? 'exited' : 'running',
            str_contains($command, 'State.Health.Status') => $server->name === 'Unhealthy Host' ? 'unhealthy' : 'healthy',
            default => "[fake] {$command}",
        };
    }

    public function upload(Server $server, string $localPath, string $remotePath): void {}

    public function download(Server $server, string $remotePath, string $localPath): void {}
}
