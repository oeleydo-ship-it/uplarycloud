<?php

namespace App\Services\Operations;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\Tenant;
use App\Services\Billing\PlanLimitService;
use App\Support\PlatformPaths;
use App\Support\RemoteShell;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BackupService
{
    public function __construct(
        private readonly ServerExecutorInterface $executor,
        private readonly OperationsLogService $logs,
        private readonly PlanLimitService $limits,
    ) {}

    public function create(Backup $backup): void
    {
        $backup->loadMissing(['deployment.server', 'destination', 'schedule']);
        $deployment = $backup->deployment;
        $directory = storage_path('app/private/backups/'.$backup->tenant_id);

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        $filename = $backup->uuid.'.tar.gz';
        $local = $directory.DIRECTORY_SEPARATOR.$filename;

        if (config('infrastructure.driver') === 'fake') {
            file_put_contents($local, gzencode(json_encode([
                'deployment' => $deployment->name,
                'image' => $deployment->docker_image.':'.$deployment->docker_tag,
                'type' => $backup->backup_type,
                'created_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR), 9), LOCK_EX);
        } else {
            $remote = PlatformPaths::backups().'/'.$filename;
            $volume = $deployment->slug.'-data';
            $backupRoot = PlatformPaths::backups();
            $this->executor->execute($backup->server, PlatformPaths::ensureTreeCommandFor($backup->server).' && docker run --rm -v '.RemoteShell::quote($volume.':/data:ro').' -v '.RemoteShell::quote($backupRoot.':/backup').' alpine:3.22 tar -czf '.RemoteShell::quote('/backup/'.$filename).' -C /data .');
            $this->executor->download($backup->server, $remote, $local);
        }

        if (! is_file($local)) {
            throw new RuntimeException('Backup artifact was not created.');
        }

        try {
            $this->limits->enforce(
                Tenant::findOrFail($backup->tenant_id),
                'backup_storage_gb',
                filesize($local) / 1073741824,
            );
        } catch (\Throwable $exception) {
            @unlink($local);
            throw $exception;
        }

        $metadata = ['destination' => $backup->destination?->provider ?? 'local'];

        if ($this->isRemote($backup->destination)) {
            $remoteKey = 'tenants/'.$backup->tenant_id.'/backups/'.$filename;
            $stream = fopen($local, 'rb');

            try {
                $this->disk($backup->destination)->put($remoteKey, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $metadata['remote_key'] = $remoteKey;
            $backup->destination->update(['last_verified_at' => now()]);
        }

        $backup->update([
            'status' => 'successful',
            'storage_path' => $local,
            'size_bytes' => filesize($local),
            'checksum' => hash_file('sha256', $local),
            'completed_at' => now(),
            'expires_at' => now()->addDays($backup->schedule?->delete_after_days ?? 30),
            'metadata' => $metadata,
        ]);

        $deployment->server->volumes()->where('docker_name', $deployment->slug.'-data')->update(['backed_up_at' => now()]);
        $this->logs->write($backup->tenant_id, 'backup', 'info', $backup->name.' completed', [
            'server_id' => $backup->server_id,
            'application_deployment_id' => $deployment->id,
            'backup_id' => $backup->id,
        ]);
    }

    public function restore(Backup $backup): void
    {
        $local = $this->downloadPath($backup);

        if (config('infrastructure.driver') !== 'fake') {
            $remote = PlatformPaths::backups().'/restore-'.$backup->uuid.'.tar.gz';
            $backupRoot = PlatformPaths::backups();
            $this->executor->execute($backup->server, PlatformPaths::ensureTreeCommandFor($backup->server));
            $this->executor->upload($backup->server, $local, $remote);
            $this->executor->execute($backup->server, 'docker run --rm -v '.RemoteShell::quote($backup->deployment->slug.'-data:/data').' -v '.RemoteShell::quote($backupRoot.':/backup').' alpine:3.22 sh -lc '.RemoteShell::quote('rm -rf /data/* && tar -xzf /backup/'.basename($remote).' -C /data'));
        }

        $backup->update(['restored_at' => now()]);
        $this->logs->write($backup->tenant_id, 'backup', 'warning', $backup->name.' restored to '.$backup->deployment->name, [
            'server_id' => $backup->server_id,
            'application_deployment_id' => $backup->application_deployment_id,
            'backup_id' => $backup->id,
        ]);
    }

    public function downloadPath(Backup $backup): string
    {
        $backup->loadMissing('destination');

        if ($backup->status !== 'successful') {
            throw new RuntimeException('A successful backup artifact is required.');
        }

        if ($backup->storage_path && is_file($backup->storage_path)) {
            return $backup->storage_path;
        }

        $remoteKey = $backup->metadata['remote_key'] ?? null;

        if (! $remoteKey || ! $this->isRemote($backup->destination)) {
            throw new RuntimeException('The backup artifact is unavailable.');
        }

        $directory = storage_path('app/private/backups/'.$backup->tenant_id);
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        $local = $directory.DIRECTORY_SEPARATOR.$backup->uuid.'.tar.gz';
        $stream = $this->disk($backup->destination)->readStream($remoteKey);

        if (! is_resource($stream)) {
            throw new RuntimeException('The remote backup could not be downloaded.');
        }

        $target = fopen($local, 'wb');
        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            if (is_resource($target)) {
                fclose($target);
            }
        }

        if ($backup->checksum && ! hash_equals($backup->checksum, hash_file('sha256', $local))) {
            @unlink($local);
            throw new RuntimeException('The downloaded backup failed checksum verification.');
        }

        $backup->update(['storage_path' => $local]);

        return $local;
    }

    public function delete(Backup $backup): void
    {
        $backup->loadMissing('destination');
        $remoteKey = $backup->metadata['remote_key'] ?? null;

        if ($remoteKey && $this->isRemote($backup->destination)) {
            $this->disk($backup->destination)->delete($remoteKey);
        }

        if ($backup->storage_path && is_file($backup->storage_path)) {
            @unlink($backup->storage_path);
        }

        $backup->delete();
    }

    private function isRemote(?BackupDestination $destination): bool
    {
        return $destination && $destination->provider !== 'local';
    }

    private function disk(BackupDestination $destination): FilesystemAdapter
    {
        return Storage::build([
            'driver' => 's3',
            'key' => $destination->access_key,
            'secret' => $destination->secret_key,
            'region' => $destination->region ?: ($destination->provider === 's3' ? 'us-east-1' : 'auto'),
            'bucket' => $destination->bucket,
            'endpoint' => $destination->endpoint ?: null,
            'use_path_style_endpoint' => in_array($destination->provider, ['r2', 'b2', 'custom_s3'], true),
            'throw' => true,
        ]);
    }
}
