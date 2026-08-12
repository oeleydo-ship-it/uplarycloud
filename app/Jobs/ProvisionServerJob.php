<?php

namespace App\Jobs;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ServerStatus;
use App\Events\ServerProvisioningUpdated;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\Servers\ServerProvisionVerifier;
use App\Support\RemoteShell;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProvisionServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;
    public array $backoff = [15, 60, 180];

    public function __construct(public Server $server, public bool $force = false)
    {
        $this->onQueue(config('infrastructure.queues.provisioning'));
    }

    private const PROVISION_TIMEOUT = 900;

    public function handle(ServerExecutorInterface $executor, ServerProvisionVerifier $verifier): void
    {
        $server = $this->server->fresh(['credential', 'provisioningSteps']);
        if (! $this->force && $server->status === ServerStatus::Online && $server->isFullyProvisioned()) {
            try {
                $verifier->assertProvisioned($server);

                return;
            } catch (Throwable) {
                $this->force = true;
            }
        }

        $this->guardDriver($verifier, $server);

        $server->update(['status' => ServerStatus::Provisioning, 'failure_reason' => null]);
        ActivityLog::create([
            'tenant_id' => $server->tenant_id,
            'action' => 'server.provisioning.started',
            'description' => $server->name.' provisioning started ('.config('infrastructure.driver').' driver)',
            'subject_type' => Server::class,
            'subject_id' => $server->id,
            'metadata' => ['driver' => config('infrastructure.driver'), 'force' => $this->force],
            'created_at' => now(),
        ]);

        $system = [];
        foreach ($server->provisioningSteps()->orderBy('position')->get() as $step) {
            if (! $this->force && $step->status === 'completed') {
                continue;
            }

            $this->running($server, $step, $step->label.'…');
            try {
                $message = match ($step->key) {
                    'connect' => $this->connect($executor, $server, $system),
                    'system' => $this->system($system),
                    'docker' => $server->install_docker ? $this->docker($executor, $server) : 'Docker installation skipped by request',
                    'configure' => $this->configure($executor, $server),
                    'proxy' => $server->install_proxy ? $this->proxy($executor, $server) : 'Reverse proxy installation skipped',
                    'monitoring' => $server->install_monitoring ? $this->monitoring($executor, $server) : 'Monitoring installation skipped',
                    'verify' => $this->verify($executor, $server, $verifier),
                    default => throw new RuntimeException('Unknown provisioning step.'),
                };
                $this->completed($server, $step, $message);
            } catch (Throwable $exception) {
                $step->update(['status' => 'failed', 'completed_at' => now(), 'message' => $this->safeError($exception)]);
                $server->update(['status' => ServerStatus::Failed, 'failure_reason' => $this->safeError($exception)]);
                ActivityLog::create([
                    'tenant_id' => $server->tenant_id,
                    'action' => 'server.provisioning.failed',
                    'description' => $server->name.' provisioning failed at '.$step->label,
                    'subject_type' => Server::class,
                    'subject_id' => $server->id,
                    'metadata' => ['step' => $step->key, 'message' => $this->safeError($exception)],
                    'created_at' => now(),
                ]);
                event(new ServerProvisioningUpdated($server, $step->key, 'failed', $step->message));
                throw $exception;
            }
        }

        $verifier->assertProvisioned($server);

        $dockerVersion = trim($executor->execute($server, "docker version --format '{{.Server.Version}}'"));
        $composeVersion = trim($executor->execute($server, 'docker compose version --short'));
        $server->update([
            'status' => ServerStatus::Online,
            'docker_version' => $dockerVersion,
            'docker_compose_version' => $composeVersion,
            'last_seen_at' => now(),
            'provisioned_at' => now(),
            'failure_reason' => null,
        ]);
        ActivityLog::create([
            'tenant_id' => $server->tenant_id,
            'action' => 'server.provisioned',
            'description' => $server->name.' provisioned successfully',
            'subject_type' => Server::class,
            'subject_id' => $server->id,
            'metadata' => [
                'driver' => config('infrastructure.driver'),
                'docker_version' => $dockerVersion,
                'docker_compose_version' => $composeVersion,
            ],
            'created_at' => now(),
        ]);
    }

    private function guardDriver(ServerProvisionVerifier $verifier, Server $server): void
    {
        if (config('infrastructure.driver') === 'ssh') {
            return;
        }

        if ($verifier->allowsSimulatedProvisioning($server->ip_address)) {
            return;
        }

        throw new RuntimeException(
            'Live hosts require INFRASTRUCTURE_DRIVER=ssh. The fake driver cannot install Docker on public servers.'
        );
    }

    private function connect(ServerExecutorInterface $executor, Server $server, array &$system): string
    {
        $result = $executor->test($server);
        if (! $result['success']) {
            throw new RuntimeException($result['message'] ?? 'SSH connection failed.');
        }
        $system = $result['system'];
        $server->update(array_intersect_key($system, array_flip(['operating_system', 'cpu_cores', 'memory_mb', 'disk_gb'])));

        return 'Secure SSH connection and sudo access verified';
    }

    private function system(array $system): string
    {
        if (($system['memory_mb'] ?? 0) < 1800) {
            throw new RuntimeException('At least 2 GB of RAM is required.');
        }
        if (($system['disk_gb'] ?? 0) < 15) {
            throw new RuntimeException('At least 15 GB of disk space is required.');
        }

        return ($system['operating_system'] ?? 'Linux').' meets CPU, memory and disk requirements';
    }

    private function docker(ServerExecutorInterface $executor, Server $server): string
    {
        $executor->execute($server, $this->sudo($server, "set -e; export DEBIAN_FRONTEND=noninteractive; command -v curl >/dev/null || (apt-get update -y && apt-get install -y curl ca-certificates); if ! command -v docker >/dev/null; then curl -fsSL https://get.docker.com | sh; fi; systemctl enable --now docker; docker version --format '{{.Server.Version}}'; docker compose version --short"), self::PROVISION_TIMEOUT);

        return 'Docker Engine and Compose plugin installed';
    }

    private function configure(ServerExecutorInterface $executor, Server $server): string
    {
        $executor->execute($server, $this->sudo($server, "set -e; install -d -m 0750 /opt/uplary/{apps,backups,traefik,monitoring}; docker network inspect uplary-proxy >/dev/null 2>&1 || docker network create uplary-proxy; cat > /etc/docker/daemon.json <<'JSON'\n{\"log-driver\":\"json-file\",\"log-opts\":{\"max-size\":\"10m\",\"max-file\":\"3\"},\"live-restore\":true}\nJSON\nsystemctl restart docker"), self::PROVISION_TIMEOUT);

        return 'Docker daemon, directories and private proxy network configured';
    }

    private function proxy(ServerExecutorInterface $executor, Server $server): string
    {
        $image = config('networking.proxy_image', 'traefik:v3.1');
        $name = config('networking.proxy_name', 'uplary-traefik');
        $network = config('networking.proxy_network', 'uplary-proxy');
        $dynamic = rtrim((string) config('networking.proxy_dynamic_path', '/opt/uplary/traefik/dynamic'), '/');
        $certificates = config('networking.proxy_certificates_volume', 'uplary-traefik-certs');
        // The file provider and ACME storage must exist from the start: domain
        // configuration writes router files into the dynamic directory.
        $run = 'docker run -d --name '.RemoteShell::quote($name).' --restart unless-stopped --network '.RemoteShell::quote($network)
            .' -p 80:80 -p 443:443 -v /var/run/docker.sock:/var/run/docker.sock:ro'
            .' -v '.RemoteShell::quote($dynamic.':/etc/traefik/dynamic:ro')
            .' -v '.RemoteShell::quote($certificates.':/letsencrypt').' '.RemoteShell::quote($image)
            .' --providers.docker=true --providers.docker.exposedbydefault=false'
            .' --providers.file.directory=/etc/traefik/dynamic --providers.file.watch=true'
            .' --entrypoints.web.address=:80 --entrypoints.websecure.address=:443'
            .' --certificatesresolvers.letsencrypt.acme.httpchallenge=true'
            .' --certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web'
            .' --certificatesresolvers.letsencrypt.acme.email='.RemoteShell::quote(config('networking.acme_email'))
            .' --certificatesresolvers.letsencrypt.acme.storage=/letsencrypt/acme.json';
        $executor->execute($server, $this->sudo($server, 'install -d -m 0750 '.RemoteShell::quote($dynamic).'; docker volume inspect '.RemoteShell::quote($certificates).' >/dev/null 2>&1 || docker volume create '.RemoteShell::quote($certificates).'; docker inspect '.RemoteShell::quote($name).' >/dev/null 2>&1 || '.$run), self::PROVISION_TIMEOUT);
        $server->update(['proxy_status' => 'running', 'proxy_version' => $image, 'proxy_network' => $network, 'proxy_installed_at' => now()]);

        return 'Traefik reverse proxy started on ports 80 and 443';
    }

    private function monitoring(ServerExecutorInterface $executor, Server $server): string
    {
        $executor->execute($server, $this->sudo($server, "cat > /opt/uplary/monitoring/health.sh <<'SH'\n#!/bin/sh\nprintf '{\"cpu\":\"%s\",\"memory_kb\":\"%s\",\"disk_percent\":\"%s\"}\\n' \"$(awk -v RS='' '{print $1}' /proc/loadavg)\" \"$(awk '/MemAvailable/{print $2}' /proc/meminfo)\" \"$(df -P / | awk 'NR==2{gsub(/%/,\"\",$5);print $5}')\"\nSH\nchmod 0750 /opt/uplary/monitoring/health.sh"));

        return 'Secure host metrics collector installed';
    }

    private function verify(ServerExecutorInterface $executor, Server $server, ServerProvisionVerifier $verifier): string
    {
        $verifier->assertProvisioned($server);
        $output = $executor->execute($server, $this->sudo($server, "set -e; docker info >/dev/null; docker compose version >/dev/null; test -d /opt/uplary/apps; echo READY"));
        if (! str_contains($output, 'READY')) {
            throw new RuntimeException('Final Docker verification did not complete.');
        }

        return 'Docker, Compose and platform directories verified';
    }

    private function sudo(Server $server, string $command): string
    {
        return $server->ssh_username === 'root' ? $command : 'sudo -n sh -c '.RemoteShell::quote($command);
    }

    private function running(Server $server, $step, string $message): void
    {
        $step->update(['status' => 'running', 'started_at' => now(), 'completed_at' => null, 'message' => $message]);
        event(new ServerProvisioningUpdated($server, $step->key, 'running', $message));
    }

    private function completed(Server $server, $step, string $message): void
    {
        $step->update(['status' => 'completed', 'completed_at' => now(), 'message' => $message]);
        ActivityLog::create([
            'tenant_id' => $server->tenant_id,
            'action' => 'server.provisioning.step',
            'description' => $server->name.': '.$step->label.' completed',
            'subject_type' => Server::class,
            'subject_id' => $server->id,
            'metadata' => ['step' => $step->key, 'message' => $message],
            'created_at' => now(),
        ]);
        event(new ServerProvisioningUpdated($server, $step->key, 'completed', $message));
    }

    private function safeError(Throwable $e): string
    {
        return app()->hasDebugModeEnabled()
            ? str($e->getMessage())->limit(300)->toString()
            : 'This provisioning step failed. Check the SSH access and server requirements, then retry.';
    }

    public function failed(Throwable $exception): void
    {
        $server = $this->server->fresh();
        if ($server->status === ServerStatus::Online && $server->isFullyProvisioned()) {
            return;
        }

        if ($server->status !== ServerStatus::Failed) {
            $server->update(['status' => ServerStatus::Failed, 'failure_reason' => 'Provisioning could not be completed.']);
        }
        event(new ServerProvisioningUpdated($this->server, 'failed', 'failed', $server->failure_reason));
    }
}
