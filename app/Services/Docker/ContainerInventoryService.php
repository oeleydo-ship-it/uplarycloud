<?php

namespace App\Services\Docker;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ContainerStatus;
use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeployment;
use App\Models\DockerContainer;
use App\Models\Server;
use App\Models\Tenant;
use App\Support\RemoteShell;

class ContainerInventoryService
{
    public function __construct(
        private readonly ServerExecutorInterface $executor,
        private readonly DockerService $docker,
    ) {}

    public function linkDeployments(Tenant $tenant): int
    {
        $linked = 0;

        ApplicationDeployment::query()
            ->where('tenant_id', $tenant->id)
            ->with('application')
            ->get()
            ->each(function (ApplicationDeployment $deployment) use (&$linked): void {
                $query = DockerContainer::query()
                    ->where('tenant_id', $deployment->tenant_id)
                    ->where('server_id', $deployment->server_id)
                    ->where(function ($builder) use ($deployment): void {
                        $builder->where('name', $deployment->slug)
                            ->orWhere('image', 'like', $deployment->docker_image.'%');
                    });

                $linked += $query->update(['application_deployment_id' => $deployment->id]);
            });

        DockerContainer::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('application_deployment_id')
            ->get()
            ->each(function (DockerContainer $container) use (&$linked): void {
                $repository = str($container->image)->before(':')->toString();
                $application = Application::query()
                    ->where('docker_image', $repository)
                    ->orWhere('docker_image', 'like', '%/'.$repository)
                    ->first();

                if (! $application) {
                    return;
                }

                $deployment = ApplicationDeployment::query()
                    ->where('tenant_id', $container->tenant_id)
                    ->where('server_id', $container->server_id)
                    ->where('application_id', $application->id)
                    ->latest('deployed_at')
                    ->first();

                if ($deployment) {
                    $container->update(['application_deployment_id' => $deployment->id]);
                    $linked++;
                }
            });

        return $linked;
    }

    public function syncTenant(Tenant $tenant, ?int $serverId = null): int
    {
        $this->linkDeployments($tenant);

        $servers = Server::query()
            ->where('tenant_id', $tenant->id)
            ->when($serverId, fn ($q) => $q->whereKey($serverId))
            ->where('status', 'online')
            ->get();

        $updated = 0;
        foreach ($servers as $server) {
            $updated += $this->syncServer($server);
        }

        return $updated;
    }

    public function syncServer(Server $server): int
    {
        if (config('infrastructure.driver') === 'fake') {
            return $this->syncFakeServer($server);
        }

        $raw = trim($this->executor->execute(
            $server,
            'docker ps -a --no-trunc --format '.RemoteShell::quote('{{json .}}')
        ));

        if ($raw === '' || str_starts_with($raw, '[fake]')) {
            return $this->syncFakeServer($server);
        }

        $seen = [];
        $updated = 0;

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }

            $name = ltrim((string) ($row['Names'] ?? ''), '/');
            if ($name === '') {
                continue;
            }

            $name = explode(',', $name)[0];
            $seen[] = $name;

            $state = strtolower((string) ($row['State'] ?? 'created'));
            $statusText = (string) ($row['Status'] ?? '');
            $health = $this->healthFromStatusText($statusText);
            $status = $this->docker->mapDockerState($state, $health);

            $container = DockerContainer::withTrashed()->updateOrCreate(
                [
                    'tenant_id' => $server->tenant_id,
                    'server_id' => $server->id,
                    'name' => $name,
                ],
                [
                    'docker_id' => isset($row['ID']) ? substr((string) $row['ID'], 0, 12) : null,
                    'image' => (string) ($row['Image'] ?? 'unknown'),
                    'status' => $status,
                    'health' => $health,
                    'ports' => $this->docker->parsePsPorts((string) ($row['Ports'] ?? '')),
                    'labels' => $this->parseLabelString((string) ($row['Labels'] ?? '')),
                    'deleted_at' => null,
                ]
            );

            try {
                // Inspect + stats so memory_limit_mb / memory_usage_mb come from Docker, not stale defaults.
                $this->docker->refreshContainer($container);
            } catch (\Throwable $exception) {
                report($exception);
            }

            $this->annotateDeploymentExpectation($container->fresh() ?? $container);
            $updated++;
        }

        if ($seen !== []) {
            DockerContainer::query()
                ->where('server_id', $server->id)
                ->where('tenant_id', $server->tenant_id)
                ->whereNotIn('name', $seen)
                ->whereNotIn('status', [ContainerStatus::Stopped, ContainerStatus::Exited])
                ->update([
                    'status' => ContainerStatus::Exited,
                    'finished_at' => now(),
                    'health' => null,
                ]);
        }

        return $updated;
    }

    public function refreshOne(DockerContainer $container): void
    {
        $this->docker->refreshContainer($container);
        $this->annotateDeploymentExpectation($container->fresh() ?? $container);
    }

    private function syncFakeServer(Server $server): int
    {
        $updated = 0;

        DockerContainer::query()
            ->where('server_id', $server->id)
            ->where('tenant_id', $server->tenant_id)
            ->get()
            ->each(function (DockerContainer $container) use (&$updated): void {
                $this->docker->applyDeploymentHealth($container);
                $this->annotateDeploymentExpectation($container->fresh() ?? $container);
                $updated++;
            });

        return $updated;
    }

    private function annotateDeploymentExpectation(DockerContainer $container): void
    {
        $container->loadMissing('deployment');
        $deployment = $container->deployment;

        if (! $deployment || $deployment->status !== DeploymentStatus::Running) {
            return;
        }

        if (in_array($container->status, [ContainerStatus::Stopped, ContainerStatus::Exited, ContainerStatus::Created], true)) {
            // Keep explicit stopped/exited status so the UI shows the workload is down.
            $container->update(['health' => $container->health ?: 'stopped']);
        }
    }

    private function healthFromStatusText(string $statusText): ?string
    {
        if (str_contains($statusText, '(healthy)')) {
            return 'healthy';
        }
        if (str_contains($statusText, '(unhealthy)')) {
            return 'unhealthy';
        }
        if (str_contains($statusText, '(health: starting)')) {
            return 'starting';
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function parseLabelString(string $labels): ?array
    {
        if ($labels === '' || $labels === '-') {
            return null;
        }

        $parsed = [];
        foreach (explode(',', $labels) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $parsed[$key] = $value;
        }

        return $parsed === [] ? null : $parsed;
    }
}
