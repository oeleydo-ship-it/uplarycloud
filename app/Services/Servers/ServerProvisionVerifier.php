<?php

namespace App\Services\Servers;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Models\Server;
use App\Support\PlatformPaths;
use App\Support\RemoteShell;
use RuntimeException;
use Throwable;

class ServerProvisionVerifier
{
    public function __construct(private readonly ServerExecutorInterface $executor) {}

    public function assertProvisioned(Server $server): void
    {
        $failures = $this->failures($server);
        if ($failures !== []) {
            throw new RuntimeException(implode(' ', $failures));
        }
    }

    /**
     * @return list<string>
     */
    public function failures(Server $server): array
    {
        if (config('infrastructure.driver') !== 'ssh') {
            return [];
        }

        $failures = [];

        if ($server->install_docker) {
            try {
                $docker = trim($this->run($server, 'command -v docker >/dev/null 2>&1 && docker version --format "{{.Server.Version}}"'));
                if ($docker === '' || str_starts_with($docker, '[fake]')) {
                    $failures[] = 'Docker Engine is not running on the host.';
                }
            } catch (Throwable) {
                $failures[] = 'Docker Engine is not installed on the host.';
            }

            try {
                $compose = trim($this->run($server, 'docker compose version --short'));
                if ($compose === '' || str_starts_with($compose, '[fake]')) {
                    $failures[] = 'Docker Compose is not available on the host.';
                }
            } catch (Throwable) {
                $failures[] = 'Docker Compose is not available on the host.';
            }

            try {
                $this->run($server, 'test -d '.RemoteShell::quote(PlatformPaths::apps()));
                $this->run($server, 'test -d '.RemoteShell::quote(PlatformPaths::builds()));
            } catch (Throwable) {
                $failures[] = 'Platform directories were not created on the host.';
            }
        }

        if ($server->install_proxy) {
            try {
                $traefik = trim($this->run(
                    $server,
                    'docker ps --filter name=uplary-traefik --filter status=running --format "{{.Names}}"'
                ));
                if ($traefik === '') {
                    $failures[] = 'Traefik reverse proxy is not running on the host.';
                }
            } catch (Throwable) {
                $failures[] = 'Traefik reverse proxy is not running on the host.';
            }
        }

        if ($server->install_monitoring) {
            try {
                $this->run($server, 'test -x '.RemoteShell::quote(PlatformPaths::monitoring().'/health.sh'));
            } catch (Throwable) {
                $failures[] = 'Host metrics collector was not installed.';
            }
        }

        return $failures;
    }

    private function run(Server $server, string $command): string
    {
        if (strcasecmp((string) $server->ssh_username, 'root') !== 0) {
            $command = 'sudo -n sh -c '.RemoteShell::quote($command);
        }

        return $this->executor->execute($server, $command);
    }

    public function allowsSimulatedProvisioning(string $ipAddress): bool
    {
        if (! filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        foreach (['192.0.2.', '198.51.100.', '203.0.113.'] as $prefix) {
            if (str_starts_with($ipAddress, $prefix)) {
                return true;
            }
        }

        return filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
