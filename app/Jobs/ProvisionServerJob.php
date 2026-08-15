<?php

namespace App\Jobs;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ServerStatus;
use App\Events\ServerProvisioningUpdated;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\Infrastructure\ManagedInfrastructureService;
use App\Services\Servers\ServerProvisionVerifier;
use App\Support\PlatformPaths;
use App\Support\RemoteShell;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProvisionServerJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 20;
    public int $timeout = 900;
    public array $backoff = [10, 15, 20, 30, 45, 60, 90, 120, 120];
    public int $uniqueFor = 2400;

    public function __construct(public Server $server, public bool $force = false)
    {
        $this->onQueue(config('infrastructure.queues.provisioning'));
    }

    public function uniqueId(): string
    {
        return 'provision-server-'.$this->server->getKey();
    }

    private const PROVISION_TIMEOUT = 900;

    public function handle(ServerExecutorInterface $executor, ServerProvisionVerifier $verifier, ManagedInfrastructureService $managedInfrastructure): void
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
                    'system' => $this->system($system, $server),
                    'docker' => $server->install_docker ? $this->docker($executor, $server) : 'Docker installation skipped by request',
                    'configure' => $this->configure($executor, $server),
                    'proxy' => $server->install_proxy ? $this->proxy($executor, $server) : 'Reverse proxy installation skipped',
                    'monitoring' => $server->install_monitoring ? $this->monitoring($executor, $server) : 'Monitoring installation skipped',
                    'verify' => $this->verify($executor, $server, $verifier),
                    default => throw new RuntimeException('Unknown provisioning step.'),
                };
                $this->completed($server, $step, $message);
            } catch (Throwable $exception) {
                if (
                    $step->key === 'connect'
                    && $this->shouldRecreateCloudDroplet($exception, $server)
                ) {
                    $publicKey = $managedInfrastructure->publicKeyFor($server);
                    if ($publicKey !== null) {
                        $this->running($server, $step, 'Recreating cloud instance with updated first-boot configuration…');
                        $managedInfrastructure->recreateDropletForProvisioning($server->fresh(), $publicKey);
                        self::dispatch($server->fresh(), force: true)->delay(now()->addSeconds(90));

                        return;
                    }
                }

                if ($this->shouldRetryWithoutFailing($exception) && $this->attempts() < $this->tries) {
                    $this->running(
                        $server,
                        $step,
                        "Cloud initialization or SSH is not ready yet. Automatic retry {$this->attempts()} of {$this->tries} is scheduled…",
                    );
                    $server->update(['status' => ServerStatus::Provisioning, 'failure_reason' => null]);
                    throw $exception;
                }

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
        if ($this->waitingForCloudInstance($server)) {
            throw new RuntimeException('The cloud instance does not have a public IP yet.');
        }

        if (filled($server->provider_resource_id) && filled($server->provider_connection_id)) {
            $this->waitForCloudInit($executor, $server);
        }

        $result = $executor->test($server);
        if (! $result['success']) {
            throw new RuntimeException($result['message'] ?? 'SSH connection failed.');
        }
        $system = $result['system'];
        $advertisedDisk = (int) $server->disk_gb;
        $system['memory_mb'] = \App\Services\Servers\ServerConnectionTester::resolveMemoryMb(
            (int) ($system['memory_mb'] ?? 0),
            (int) $server->memory_mb
        );
        if ($advertisedDisk >= 15 && (int) ($system['disk_gb'] ?? 0) < 15) {
            $system['disk_gb'] = $advertisedDisk;
        }
        $server->update(array_intersect_key($system, array_flip(['operating_system', 'cpu_cores', 'memory_mb', 'disk_gb'])));

        return 'Secure SSH connection and sudo access verified';
    }

    private function waitForCloudInit(ServerExecutorInterface $executor, Server $server): void
    {
        $command = $this->waitForAptLocksScript().'; '
            .'if command -v cloud-init >/dev/null 2>&1; then cloud-init status --wait >/dev/null 2>&1 || true; fi';

        try {
            $executor->execute($server, $this->sudo($server, $command), 420);
        } catch (Throwable) {
            throw new RuntimeException('The cloud instance is still finishing first-boot package setup.');
        }
    }

    private function waitForAptLocksScript(): string
    {
        return 'for i in $(seq 1 72); do '
            .'if ! fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 && ! fuser /var/lib/apt/lists/lock >/dev/null 2>&1; then break; fi; '
            .'sleep 5; done';
    }

    private function waitingForCloudInstance(Server $server): bool
    {
        if ($server->ip_address === '0.0.0.0' || $server->ip_address === '') {
            return true;
        }

        return (bool) ($server->provider_connection_id && blank($server->provider_resource_id));
    }

    private function shouldRetryWithoutFailing(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach ([
            'timed out', 'timeout', '10060', 'connection refused', 'could not reach',
            'not ready', '0.0.0.0', 'does not have a public ip', 'unreachable',
            'no route', 'connection reset', 'unable to connect', 'network is unreachable',
            'operating system is not supported',
            'password has expired', 'password change required', 'no tty available',
            'could not get lock', 'unable to lock directory', 'finishing first-boot',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function shouldRecreateCloudDroplet(Throwable $exception, Server $server): bool
    {
        if (! filled($server->provider_resource_id) || ! filled($server->provider_connection_id)) {
            return false;
        }

        if (strcasecmp((string) $server->ssh_username, ManagedInfrastructureService::PROVISIONING_SSH_USER) === 0) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        foreach ([
            'password expiry could not be cleared',
            'password has expired',
            'password change required',
            'you must change your password',
            'required to change your password',
            'no tty available',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function system(array $system, Server $server): string
    {
        $memory = \App\Services\Servers\ServerConnectionTester::resolveMemoryMb(
            (int) ($system['memory_mb'] ?? 0),
            (int) $server->memory_mb
        );
        $disk = (int) ($system['disk_gb'] ?? 0);
        $minMemory = \App\Services\Servers\ServerConnectionTester::MIN_MEMORY_MB;
        if ($disk < 15 && (int) $server->disk_gb >= 15) {
            $disk = (int) $server->disk_gb;
        }
        if ($memory <= 0 || $disk <= 0) {
            throw new RuntimeException('The SSH session is not ready to read CPU, memory and disk.');
        }
        if ($memory < $minMemory) {
            throw new RuntimeException('At least 2 GB of RAM is required.');
        }
        if ($disk < 15) {
            throw new RuntimeException('At least 15 GB of disk space is required.');
        }

        return ($system['operating_system'] ?? 'Linux').' meets CPU, memory and disk requirements';
    }

    private function docker(ServerExecutorInterface $executor, Server $server): string
    {
        $dockerGroup = strcasecmp((string) $server->ssh_username, 'root') !== 0
            ? 'usermod -aG docker '.escapeshellarg((string) $server->ssh_username).' || true; '
            : '';

        $executor->execute($server, $this->sudo($server, 'set -e; '.$this->waitForAptLocksScript().'; export DEBIAN_FRONTEND=noninteractive; command -v curl >/dev/null || (apt-get update -y && apt-get install -y curl ca-certificates); if ! command -v docker >/dev/null; then curl -fsSL https://get.docker.com | sh; fi; '.$dockerGroup.'systemctl enable --now docker; docker version --format \'{{.Server.Version}}\'; docker compose version --short'), self::PROVISION_TIMEOUT);

        return 'Docker Engine and Compose plugin installed';
    }

    private function configure(ServerExecutorInterface $executor, Server $server): string
    {
        $executor->execute($server, $this->sudo($server, PlatformPaths::installTreeCommand($server->ssh_username).'; docker network inspect uplary-proxy >/dev/null 2>&1 || docker network create uplary-proxy; cat > /etc/docker/daemon.json <<\'JSON\'
{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"},"live-restore":true}
JSON
systemctl restart docker'), self::PROVISION_TIMEOUT);

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
        $directory = PlatformPaths::monitoring();
        $target = $directory.'/health.sh';
        $temporary = $directory.'/.health.sh.tmp';
        $script = <<<'SH'
#!/bin/sh
set -eu
printf '{"cpu":"%s","memory_kb":"%s","disk_percent":"%s"}\n' \
    "$(awk '{print $1}' /proc/loadavg)" \
    "$(awk '/MemAvailable/{print $2}' /proc/meminfo)" \
    "$(df -P / | awk 'NR==2{gsub(/%/,"",$5);print $5}')"
SH;
        $command = 'set -eu; '
            .'install -d -m 0750 '.RemoteShell::quote($directory).'; '
            .'test -d '.RemoteShell::quote($directory).'; '
            .'printf %s '.RemoteShell::quote($script).' > '.RemoteShell::quote($temporary).'; '
            .'chmod 0750 '.RemoteShell::quote($temporary).'; '
            .'mv -f '.RemoteShell::quote($temporary).' '.RemoteShell::quote($target).'; '
            .'test -x '.RemoteShell::quote($target);

        $executor->execute($server, $this->sudo($server, $command));

        return 'Secure host metrics collector installed';
    }

    private function verify(ServerExecutorInterface $executor, Server $server, ServerProvisionVerifier $verifier): string
    {
        $verifier->assertProvisioned($server);
        $output = $executor->execute($server, $this->sudo($server, 'set -e; docker info >/dev/null; docker compose version >/dev/null; test -d '.RemoteShell::quote(PlatformPaths::apps()).'; test -d '.RemoteShell::quote(PlatformPaths::builds()).'; echo READY'));
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
        if ($this->shouldRetryWithoutFailing($e)) {
            return 'The server did not become SSH-ready before the provisioning timeout. Verify that port 22 is open, the SSH credentials are valid, and cloud-init has completed, then retry provisioning.';
        }

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
