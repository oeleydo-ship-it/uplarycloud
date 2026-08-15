<?php

namespace App\Services\Docker;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ContainerStatus;
use App\Models\DockerComposeProject;
use App\Models\DockerContainer;
use App\Models\DockerImage;
use App\Models\DockerNetwork;
use App\Models\DockerVolume;
use App\Models\Server;
use App\Support\PlatformPaths;
use App\Support\RemoteShell;
use Carbon\Carbon;
use RuntimeException;

class DockerService
{
    public function __construct(private readonly ServerExecutorInterface $executor) {}

    public function container(DockerContainer $container, string $action): void
    {
        if (! in_array($action, ['start', 'stop', 'restart', 'pause', 'unpause', 'remove'], true)) {
            throw new RuntimeException('Unsupported container action.');
        }

        $container->loadMissing('server');
        $target = $container->docker_id ?: $container->name;
        $previousStatus = $container->status;

        if ($action === 'restart' && config('infrastructure.driver') !== 'fake') {
            $container->update(['status' => ContainerStatus::Restarting, 'health' => 'starting']);
        }

        if (config('infrastructure.driver') !== 'fake') {
            try {
                $this->executor->execute(
                    $container->server,
                    'docker '.$action.' '.RemoteShell::quote($target),
                    $this->commandTimeout()
                );
            } catch (\Throwable $exception) {
                if ($action === 'restart') {
                    $container->update(['status' => $previousStatus]);
                }

                throw $exception;
            }
        }

        if ($action === 'remove') {
            if (config('infrastructure.driver') === 'fake') {
                $container->update([
                    'status' => ContainerStatus::Exited,
                    'finished_at' => now(),
                    'health' => null,
                ]);
            }
            $container->delete();

            return;
        }

        if (config('infrastructure.driver') === 'fake') {
            $this->applyFakeTransition($container, $action);

            return;
        }

        $this->refreshContainer($container);

        if ($action === 'restart') {
            $container->refresh();
            if ($container->status === ContainerStatus::Restarting) {
                usleep(400_000);
                $this->refreshContainer($container);
            }
        }
    }

    public function refreshContainer(DockerContainer $container): void
    {
        if (config('infrastructure.driver') === 'fake') {
            $this->applyDeploymentHealth($container);

            return;
        }

        $target = RemoteShell::quote($container->docker_id ?: $container->name);
        $raw = trim($this->executor->execute(
            $container->server,
            'docker inspect --format '.RemoteShell::quote('{{json .}}').' '.$target
        ));

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            throw new RuntimeException('Unable to inspect container '.$container->name.'.');
        }

        $this->applyInspectPayload($container, $payload);
        $this->refreshStats($container);
        $this->applyDeploymentHealth($container->fresh() ?? $container);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyInspectPayload(DockerContainer $container, array $payload): void
    {
        $state = is_array($payload['State'] ?? null) ? $payload['State'] : [];
        $config = is_array($payload['Config'] ?? null) ? $payload['Config'] : [];
        $hostConfig = is_array($payload['HostConfig'] ?? null) ? $payload['HostConfig'] : [];
        $network = is_array($payload['NetworkSettings'] ?? null) ? $payload['NetworkSettings'] : [];

        $dockerStatus = strtolower((string) ($state['Status'] ?? 'created'));
        $health = null;
        if (is_array($state['Health'] ?? null) && ! empty($state['Health']['Status'])) {
            $health = strtolower((string) $state['Health']['Status']);
        }

        $status = $this->mapDockerState($dockerStatus, $health);
        // HostConfig is authoritative for cgroup limits. Docker reports Memory=0 when unlimited —
        // never keep a stale invented cap (e.g. seed/stats host RAM → "1.0 GB").
        $memoryLimit = $container->memory_limit_mb;
        if ($hostConfig !== []) {
            $memoryBytes = (int) ($hostConfig['Memory'] ?? 0);
            $memoryLimit = $memoryBytes > 0
                ? max(1, (int) round($memoryBytes / 1048576))
                : null;
        }

        $container->update([
            'docker_id' => isset($payload['Id']) ? substr((string) $payload['Id'], 0, 12) : $container->docker_id,
            'image' => (string) ($config['Image'] ?? $container->image),
            'status' => $status,
            'health' => $health,
            // Always trust inspect: empty means no host publishes (EXPOSE-only / Traefik-only).
            'ports' => $this->parseInspectPorts($network['Ports'] ?? null),
            'restart_count' => (int) ($state['RestartCount'] ?? $container->restart_count),
            'memory_limit_mb' => $memoryLimit,
            'started_at' => $this->parseDockerTime($state['StartedAt'] ?? null) ?? $container->started_at,
            'finished_at' => $this->parseDockerTime($state['FinishedAt'] ?? null),
            'labels' => is_array($config['Labels'] ?? null) ? $config['Labels'] : $container->labels,
        ]);
    }

    public function applyFakeTransition(DockerContainer $container, string $action): void
    {
        $status = match ($action) {
            'start', 'restart', 'unpause' => ContainerStatus::Running,
            'pause' => ContainerStatus::Paused,
            default => ContainerStatus::Stopped,
        };

        $health = $status === ContainerStatus::Running
            ? ($container->health && $container->health !== 'unhealthy' ? $container->health : 'healthy')
            : null;

        $container->update([
            'status' => $status,
            'health' => $health,
            'started_at' => $status === ContainerStatus::Running ? now() : $container->started_at,
            'finished_at' => in_array($status, [ContainerStatus::Stopped, ContainerStatus::Exited], true) ? now() : null,
            'restart_count' => $action === 'restart' ? $container->restart_count + 1 : $container->restart_count,
        ]);

        $this->applyDeploymentHealth($container->fresh() ?? $container);
    }

    public function applyDeploymentHealth(DockerContainer $container): void
    {
        $container->loadMissing('deployment');

        if ($container->status === ContainerStatus::Running) {
            if ($container->health === 'unhealthy') {
                $container->update(['status' => ContainerStatus::Unhealthy]);
            }

            return;
        }

        if ($container->status === ContainerStatus::Unhealthy && in_array($container->health, [null, 'healthy', 'starting'], true)) {
            $container->update(['status' => ContainerStatus::Running]);
        }
    }

    public function pull(DockerImage $image): void
    {
        if (config('infrastructure.driver') !== 'fake') {
            $this->executor->execute($image->server, 'docker image pull '.RemoteShell::quote($image->repository.':'.$image->tag));
        }
        $image->update(['status' => 'available', 'pulled_at' => now(), 'update_available' => false]);
    }

    public function removeImage(DockerImage $image): void
    {
        if ($image->used_by_count > 0) {
            throw new RuntimeException('An image used by containers cannot be removed.');
        }
        if (config('infrastructure.driver') !== 'fake') {
            $this->executor->execute($image->server, 'docker image rm '.RemoteShell::quote($image->docker_id ?: $image->repository.':'.$image->tag));
        }
        $image->delete();
    }

    public function removeVolume(DockerVolume $volume): void
    {
        $volume->loadMissing('server');

        if (config('infrastructure.driver') !== 'fake') {
            $this->removeHostContainersUsingVolume($volume);
            $this->executor->execute($volume->server, 'docker volume rm '.RemoteShell::quote($volume->docker_name));
        }

        $volume->containers()->detach();
        $volume->delete();
    }

    /**
     * Docker may still have containers referencing a volume after the control-plane
     * inventory dropped the pivot link (failed deploy, manual docker rm, etc.).
     */
    private function removeHostContainersUsingVolume(DockerVolume $volume): void
    {
        $filterName = RemoteShell::quote($volume->docker_name);
        $raw = trim($this->executor->execute(
            $volume->server,
            'docker ps -a --filter volume='.$filterName.' --format '.RemoteShell::quote('{{.ID}}'),
            $this->commandTimeout()
        ));

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $dockerId) {
            $dockerId = trim($dockerId);
            if ($dockerId === '' || str_starts_with($dockerId, '[fake]')) {
                continue;
            }

            $this->executor->execute(
                $volume->server,
                'docker rm -f '.RemoteShell::quote($dockerId),
                $this->commandTimeout()
            );

            $shortId = substr($dockerId, 0, 12);
            DockerContainer::query()
                ->where('tenant_id', $volume->tenant_id)
                ->where('server_id', $volume->server_id)
                ->where(function ($query) use ($dockerId, $shortId): void {
                    $query->where('docker_id', $dockerId)
                        ->orWhere('docker_id', $shortId);
                })
                ->get()
                ->each(function (DockerContainer $container) use ($volume): void {
                    $volume->containers()->detach($container->id);
                    $container->delete();
                });
        }
    }

    public function removeNetwork(DockerNetwork $network): void
    {
        if ($network->containers()->exists()) {
            throw new RuntimeException('Disconnect all containers before removing this network.');
        }
        if (config('infrastructure.driver') !== 'fake') {
            $this->executor->execute($network->server, 'docker network rm '.RemoteShell::quote($network->name));
        }
        $network->delete();
    }

    public function deployCompose(DockerComposeProject $project): void
    {
        if (config('infrastructure.driver') !== 'fake') {
            $directory = PlatformPaths::apps().'/'.$project->uuid;
            $temporary = storage_path('app/private/compose-'.$project->uuid.'.yml');
            if (! is_dir(dirname($temporary))) {
                mkdir(dirname($temporary), 0750, true);
            }
            file_put_contents($temporary, $project->compose_content, LOCK_EX);
            try {
                $this->executor->execute($project->server, PlatformPaths::ensureTreeCommandFor($project->server).' && install -d -m 0755 '.RemoteShell::quote($directory));
                $this->executor->upload($project->server, $temporary, $directory.'/docker-compose.yml');
                $this->executor->execute($project->server, 'docker compose -f '.RemoteShell::quote($directory.'/docker-compose.yml').' up -d --remove-orphans');
            } finally {
                @unlink($temporary);
            }
        }
        $project->update(['status' => 'running', 'deployed_at' => now(), 'last_error' => null]);
    }

    public function pruneContainers(Server $server): void
    {
        if (config('infrastructure.driver') !== 'fake') {
            $this->executor->execute($server, 'docker container prune -f');
        }
    }

    public function mapDockerState(string $dockerStatus, ?string $health = null): ContainerStatus
    {
        $status = match ($dockerStatus) {
            'running' => ContainerStatus::Running,
            'restarting' => ContainerStatus::Restarting,
            'paused' => ContainerStatus::Paused,
            'created' => ContainerStatus::Created,
            'exited' => ContainerStatus::Exited,
            'dead', 'removing' => ContainerStatus::Exited,
            default => ContainerStatus::Stopped,
        };

        if ($status === ContainerStatus::Running && $health === 'unhealthy') {
            return ContainerStatus::Unhealthy;
        }

        return $status;
    }

    /**
     * @return list<array{private?: int|string, public?: int|string}>
     */
    public function parseInspectPorts(mixed $ports): array
    {
        if (! is_array($ports)) {
            return [];
        }

        $mapped = [];
        foreach ($ports as $privateKey => $bindings) {
            $private = (int) str_replace(['/tcp', '/udp'], '', (string) $privateKey);
            // EXPOSE-only ports show up as null/[] with no HostPort — those are internal,
            // not published to the host. Only keep real publish bindings for the inventory UI.
            if (! is_array($bindings) || $bindings === []) {
                continue;
            }

            foreach ($bindings as $binding) {
                if (! is_array($binding) || empty($binding['HostPort'])) {
                    continue;
                }
                $mapped[] = [
                    'private' => $private,
                    'public' => (int) $binding['HostPort'],
                ];
            }
        }

        return array_values($mapped);
    }

    /**
     * @return list<array{private?: int|string, public?: int|string}>
     */
    public function parsePsPorts(string $ports): array
    {
        if ($ports === '' || $ports === '-') {
            return [];
        }

        preg_match_all('/(?:[\d.]+:)?(\d+)->(\d+)\/(?:tcp|udp)/', $ports, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return [];
        }

        return array_map(fn (array $match) => [
            'public' => (int) $match[1],
            'private' => (int) $match[2],
        ], $matches);
    }

    private function refreshStats(DockerContainer $container): void
    {
        try {
            $raw = trim($this->executor->execute(
                $container->server,
                'docker stats --no-stream --format '.RemoteShell::quote('{{.CPUPerc}}|{{.MemUsage}}').' '.RemoteShell::quote($container->docker_id ?: $container->name)
            ));
        } catch (\Throwable) {
            return;
        }

        if ($raw === '' || str_starts_with($raw, '[fake]')) {
            return;
        }

        [$cpuRaw, $memRaw] = array_pad(explode('|', $raw, 2), 2, null);
        $cpu = (float) str_replace('%', '', (string) $cpuRaw);
        $usageMb = 0;
        if (is_string($memRaw) && preg_match('/([\d.]+)\s*([KMGT]?i?B)/i', $memRaw, $match)) {
            $usageMb = $this->toMegabytes((float) $match[1], $match[2]);
        }

        // Stats MemUsage is "used / limit-or-host-RAM". Never persist the right-hand side —
        // when unlimited, Docker reports host total (often ~1.0GiB) which looks hardcoded.
        // Limits come only from HostConfig.Memory via applyInspectPayload.
        $container->update([
            'cpu_percent' => min(100, round($cpu, 2)),
            'memory_usage_mb' => max(0, (int) round($usageMb)),
        ]);
    }

    private function toMegabytes(float $value, string $unit): float
    {
        return match (strtoupper($unit)) {
            'B' => $value / 1048576,
            'KB', 'KIB' => $value / 1024,
            'MB', 'MIB' => $value,
            'GB', 'GIB' => $value * 1024,
            'TB', 'TIB' => $value * 1048576,
            default => $value,
        };
    }

    private function parseDockerTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0001-01-01')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function commandTimeout(): int
    {
        return max(1, (int) config('infrastructure.command_timeouts.default', 180));
    }
}
