<?php

namespace App\Services\Servers;

use App\Models\AlertRule;
use App\Models\Backup;
use App\Models\DockerComposeProject;
use App\Models\DockerContainer;
use App\Models\DockerImage;
use App\Models\DockerNetwork;
use App\Models\DockerVolume;
use App\Models\Domain;
use App\Models\OperationalLog;
use App\Models\Server;
use App\Services\Operations\BackupService;

class ServerInventoryCleanupService
{
    public function __construct(private readonly BackupService $backups) {}

    /**
     * Remove control-plane inventory for a server after it has been destroyed.
     * Remote host data is not touched — this only clears UI/DB rows.
     */
    public function purge(Server $server): void
    {
        $serverId = $server->id;

        OperationalLog::query()
            ->where('server_id', $serverId)
            ->update([
                'docker_container_id' => null,
                'backup_id' => null,
            ]);

        AlertRule::query()->where('server_id', $serverId)->each(function (AlertRule $rule): void {
            $rule->incidents()->delete();
            $rule->delete();
        });

        DockerContainer::withTrashed()
            ->where('server_id', $serverId)
            ->get()
            ->each(function (DockerContainer $container): void {
                $container->volumes()->detach();
                $container->networks()->detach();
                $container->metrics()->delete();
                $container->forceDelete();
            });

        DockerVolume::withTrashed()->where('server_id', $serverId)->forceDelete();
        DockerImage::withTrashed()->where('server_id', $serverId)->forceDelete();
        DockerNetwork::query()->where('server_id', $serverId)->delete();
        DockerComposeProject::query()->where('server_id', $serverId)->delete();
        Domain::query()->where('server_id', $serverId)->delete();

        Backup::query()->where('server_id', $serverId)->get()->each(function (Backup $backup): void {
            $this->backups->delete($backup);
        });
    }
}
