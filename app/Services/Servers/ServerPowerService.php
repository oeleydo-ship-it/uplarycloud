<?php

namespace App\Services\Servers;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ServerStatus;
use App\Jobs\ProvisionServerJob;
use App\Models\ActivityLog;
use App\Models\InfrastructureOperation;
use App\Models\Server;
use App\Services\Infrastructure\ManagedInfrastructureService;
use App\Support\PlatformPaths;
use App\Support\RemoteShell;
use RuntimeException;

class ServerPowerService
{
    public function __construct(
        private readonly ManagedInfrastructureService $managedInfrastructure,
        private readonly ServerExecutorInterface $executor,
        private readonly ServerInventoryCleanupService $inventoryCleanup,
    ) {}

    public function shutdown(Server $server, ?int $userId = null): void
    {
        $this->assertCanPower($server);

        if ($this->isCloudManaged($server)) {
            $this->runCloudAction($server, 'poweroff', $userId);

            return;
        }

        $this->executor->execute(
            $server,
            $this->sudo($server, 'nohup shutdown -h now >/dev/null 2>&1 &'),
            30
        );
        $server->update(['status' => ServerStatus::Offline, 'last_seen_at' => now()]);
        $this->log($server, $userId, 'server.shutdown', 'Shutdown requested for '.$server->name);
    }

    public function reboot(Server $server, ?int $userId = null): void
    {
        $this->assertCanPower($server);

        if ($this->isCloudManaged($server)) {
            $this->runCloudAction($server, 'restart', $userId);

            return;
        }

        $this->executor->execute(
            $server,
            $this->sudo($server, 'nohup reboot >/dev/null 2>&1 &'),
            30
        );
        $this->log($server, $userId, 'server.reboot', 'Reboot requested for '.$server->name);
    }

    public function restore(Server $server, ?int $userId = null): void
    {
        $this->assertCanPower($server);

        if ($server->applicationDeployments()->exists()) {
            throw new RuntimeException('Remove attached applications before restoring this server to a clean Linux install.');
        }

        $this->inventoryCleanup->purge($server);
        $this->resetProvisioningState($server);

        if ($this->isCloudManaged($server)) {
            $publicKey = $this->managedInfrastructure->publicKeyFor($server);
            if ($publicKey === null) {
                throw new RuntimeException('Could not resolve the SSH public key for this cloud server.');
            }

            $this->managedInfrastructure->recreateDropletForProvisioning($server->fresh(), $publicKey);
            ProvisionServerJob::dispatch($server->fresh(), force: true)->delay(now()->addSeconds(90));
            $this->log($server, $userId, 'server.restore', $server->name.' is being recreated with a clean OS and reprovisioned.');

            return;
        }

        $this->wipeRemotePlatform($server);
        ProvisionServerJob::dispatch($server->fresh(), force: true);
        $this->log($server, $userId, 'server.restore', $server->name.' platform stack reset and reprovisioning queued.');
    }

    private function assertCanPower(Server $server): void
    {
        if ($server->isProvisioningIncomplete()) {
            throw new RuntimeException('Power actions are unavailable while provisioning is in progress.');
        }

        if ($server->infrastructureOperations()->whereIn('status', ['pending', 'running'])->exists()) {
            throw new RuntimeException('Another infrastructure operation is already in progress.');
        }
    }

    private function isCloudManaged(Server $server): bool
    {
        return filled($server->provider_connection_id) && filled($server->provider_resource_id);
    }

    private function runCloudAction(Server $server, string $action, ?int $userId): void
    {
        $operation = InfrastructureOperation::create([
            'tenant_id' => $server->tenant_id,
            'server_id' => $server->id,
            'requested_by' => $userId,
            'action' => $action,
            'status' => 'pending',
        ]);

        $this->managedInfrastructure->perform($operation->fresh());
    }

    private function resetProvisioningState(Server $server): void
    {
        $server->update([
            'status' => ServerStatus::Provisioning,
            'failure_reason' => null,
            'provisioned_at' => null,
            'docker_version' => null,
            'docker_compose_version' => null,
            'proxy_status' => 'not_installed',
            'proxy_version' => null,
            'proxy_installed_at' => null,
            'last_seen_at' => null,
        ]);

        $server->provisioningSteps()->update([
            'status' => 'pending',
            'message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    private function wipeRemotePlatform(Server $server): void
    {
        $proxyName = config('networking.proxy_name', 'uplary-traefik');
        $command = 'set -e; '
            .'if command -v docker >/dev/null 2>&1; then '
            .'docker ps -aq 2>/dev/null | xargs -r docker stop 2>/dev/null || true; '
            .'docker ps -aq 2>/dev/null | xargs -r docker rm -f 2>/dev/null || true; '
            .'docker rm -f '.escapeshellarg($proxyName).' 2>/dev/null || true; '
            .'docker volume ls -q 2>/dev/null | grep -E \'^uplary|traefik\' | xargs -r docker volume rm -f 2>/dev/null || true; '
            .'docker network ls -q --filter name=uplary 2>/dev/null | xargs -r docker network rm 2>/dev/null || true; '
            .'fi; '
            .'rm -rf '.escapeshellarg(PlatformPaths::root());

        $this->executor->execute($server, $this->sudo($server, $command), 600);
    }

    private function sudo(Server $server, string $command): string
    {
        return strcasecmp((string) $server->ssh_username, 'root') === 0
            ? $command
            : 'sudo -n sh -c '.RemoteShell::quote($command);
    }

    private function log(Server $server, ?int $userId, string $action, string $description): void
    {
        ActivityLog::create([
            'tenant_id' => $server->tenant_id,
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'subject_type' => Server::class,
            'subject_id' => $server->id,
        ]);
    }
}
