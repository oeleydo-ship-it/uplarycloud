<?php

namespace App\Services\Operations;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Models\Server;
use App\Services\Docker\ContainerInventoryService;

class MonitoringService
{
    public function __construct(
        private readonly ServerExecutorInterface $executor,
        private readonly OperationsLogService $logs,
        private readonly ContainerInventoryService $containers,
    ) {}

    public function collect(Server $server): void
    {
        $minute = (int) now()->format('i');
        $hardware = [];

        try {
            $this->containers->syncServer($server);
        } catch (\Throwable $exception) {
            report($exception);
        }

        if (config('infrastructure.driver') === 'fake') {
            $cpu = 18 + (($minute + $server->id * 11) % 54);
            $memory = 35 + (($minute + $server->id * 7) % 42);
            $disk = 42 + $server->id * 5;
            $load = round($cpu / max(1, (int) $server->cpu_cores ?: 1) / 25, 2);
            $networkIn = ($minute + 1) * 1024 * 1024 * 3;
            $networkOut = ($minute + 1) * 1024 * 1024 * 2;
        } else {
            $raw = $this->executor->execute($server, "sh -lc \"top -bn1 | awk '/Cpu\\(s\\)/{print 100-\\$8}'; free | awk '/Mem:/{print \\$3/\\$2*100}'; df -P / | awk 'NR==2{gsub(/%/,\\\"\\\",\\$5);print \\$5}'; awk '{print \\$1}' /proc/loadavg\"");
            $values = array_values(array_filter(preg_split('/\\s+/', trim($raw)) ?: []));
            [$cpu, $memory, $disk, $load] = array_pad($values, 4, 0);
            $networkIn = $networkOut = 0;
            $hardware = $this->probeHardwareSpecs($server);
        }

        $server->metrics()->create([
            'cpu_percent' => min(100, (float) $cpu),
            'memory_percent' => min(100, (float) $memory),
            'disk_percent' => min(100, (float) $disk),
            'load_average' => $load,
            'network_in_bytes' => $networkIn,
            'network_out_bytes' => $networkOut,
            'recorded_at' => now(),
        ]);

        $updates = array_merge(['last_seen_at' => now()], $hardware);

        try {
            $docker = trim($this->executor->execute($server, "docker version --format '{{.Server.Version}}'"));
            $compose = trim($this->executor->execute($server, 'docker compose version --short'));
            if ($docker !== '' && ! str_starts_with($docker, '[fake]')) {
                $updates['docker_version'] = $docker;
            }
            if ($compose !== '' && ! str_starts_with($compose, '[fake]')) {
                $updates['docker_compose_version'] = $compose;
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        $server->update($updates);

        foreach ($server->containers()->whereIn('status', ['running', 'unhealthy'])->get() as $container) {
            if (config('infrastructure.driver') === 'fake') {
                $cCpu = min(100, (float) $cpu * (.25 + ($container->id % 4) * .12));
                // Fake may invent usage; do not invent a display limit — leave memory_limit_mb alone.
                $cap = $container->memory_limit_mb ?: 2048;
                $memoryMb = min($cap, 96 + (($minute + $container->id * 17) % 700));
                $container->metrics()->create([
                    'cpu_percent' => $cCpu,
                    'memory_usage_mb' => $memoryMb,
                    'network_in_bytes' => $networkIn / (2 + $container->id % 4),
                    'network_out_bytes' => $networkOut / (2 + $container->id % 3),
                    'restart_count' => $container->restart_count,
                    'health' => $container->health,
                    'recorded_at' => now(),
                ]);
                $container->update(['cpu_percent' => $cCpu, 'memory_usage_mb' => $memoryMb]);

                continue;
            }

            try {
                $this->containers->refreshOne($container);
            } catch (\Throwable $exception) {
                report($exception);
            }

            $fresh = $container->fresh() ?? $container;
            $fresh->metrics()->create([
                'cpu_percent' => (float) $fresh->cpu_percent,
                'memory_usage_mb' => (int) $fresh->memory_usage_mb,
                'network_in_bytes' => $networkIn / (2 + $fresh->id % 4),
                'network_out_bytes' => $networkOut / (2 + $fresh->id % 3),
                'restart_count' => $fresh->restart_count,
                'health' => $fresh->health,
                'recorded_at' => now(),
            ]);
        }

        $this->logs->write($server->tenant_id, 'server', 'debug', 'Metric collection completed for '.$server->name, [
            'server_id' => $server->id,
            'source' => 'monitor',
        ]);
    }

    /**
     * Persist live host capacity so Overview never keeps FakeServerExecutor defaults.
     *
     * @return array{operating_system?: string, cpu_cores?: int, memory_mb?: int, disk_gb?: int}
     */
    private function probeHardwareSpecs(Server $server): array
    {
        try {
            $raw = $this->executor->execute(
                $server,
                "sh -lc \"nproc; awk '/MemTotal/{print \\$2}' /proc/meminfo; df -Pk / | awk 'NR==2{print \\$2}'; cat /etc/os-release\""
            );
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }

        $parts = preg_split('/\R+/', trim($raw)) ?: [];
        $cores = (int) trim((string) ($parts[0] ?? '0'));
        $memoryKb = (int) preg_replace('/\D+/', '', (string) ($parts[1] ?? '0'));
        $diskKb = (int) trim((string) ($parts[2] ?? '0'));
        $osRelease = implode("\n", array_slice($parts, 3));

        preg_match('/^ID=(?:"?)([^"\r\n]+)(?:"?)$/m', $osRelease, $id);
        preg_match('/^VERSION_ID=(?:"?)([^"\r\n]+)(?:"?)$/m', $osRelease, $version);
        $os = strtolower(($id[1] ?? '').'-'.($version[1] ?? ''));

        $updates = [];
        if ($os !== '-' && in_array($os, config('infrastructure.supported_operating_systems'), true)) {
            $updates['operating_system'] = $os;
        }
        if ($cores > 0) {
            $updates['cpu_cores'] = $cores;
        }

        $memoryMb = (int) floor($memoryKb / 1024);
        if ($memoryMb > 0) {
            $updates['memory_mb'] = $memoryMb;
        }

        $diskGb = (int) floor($diskKb / 1024 / 1024);
        if ($diskGb > 0) {
            $updates['disk_gb'] = $diskGb;
        }

        return $updates;
    }
}
