<?php

namespace App\Services\Servers;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Models\Server;
use App\Support\PlatformPaths;
use App\Support\RemoteShell;
use Illuminate\Support\Facades\View;
use Throwable;

class ServerMaintenanceService
{
    public function __construct(private readonly ServerExecutorInterface $executor) {}

    public function enable(Server $server): void
    {
        if (config('infrastructure.driver') === 'fake') {
            return;
        }

        if (! in_array($server->proxy_status, ['running', 'installed'], true)) {
            return;
        }

        $network = (string) config('networking.proxy_network');
        $container = (string) config('networking.maintenance_container', 'uplary-maintenance');
        $dynamic = rtrim((string) config('networking.proxy_dynamic_path'), '/');
        $htmlDir = PlatformPaths::root().'/maintenance';

        $localHtml = storage_path('app/private/maintenance-'.$server->uuid.'.html');
        if (! is_dir(dirname($localHtml))) {
            mkdir(dirname($localHtml), 0750, true);
        }
        file_put_contents($localHtml, View::make('networking.server-restarting')->render(), LOCK_EX);

        $localRoute = storage_path('app/private/maintenance-route-'.$server->uuid.'.yml');
        file_put_contents($localRoute, $this->maintenanceRouteConfiguration($container), LOCK_EX);

        try {
            $remoteHtml = $htmlDir.'/index.html';
            $remoteRoute = $dynamic.'/maintenance.yml';
            $temporaryHtml = '/tmp/uplary-maintenance-'.$server->uuid.'.html';
            $temporaryRoute = '/tmp/uplary-maintenance-'.$server->uuid.'.yml';

            $this->executor->execute($server, $this->sudo($server, 'install -d -m 0755 '.RemoteShell::quote($htmlDir)), 60);
            $this->executor->upload($server, $localHtml, $temporaryHtml);
            $this->executor->execute(
                $server,
                $this->sudo(
                    $server,
                    'install -m 0644 '.RemoteShell::quote($temporaryHtml).' '.RemoteShell::quote($remoteHtml)
                    .' && rm -f '.RemoteShell::quote($temporaryHtml)
                ),
                60
            );

            $run = 'docker run -d --name '.RemoteShell::quote($container)
                .' --restart unless-stopped --network '.RemoteShell::quote($network)
                .' -v '.RemoteShell::quote($htmlDir.':/usr/share/nginx/html:ro').' '
                .RemoteShell::quote((string) config('networking.maintenance_image', 'nginx:alpine'));

            $this->executor->execute(
                $server,
                $this->sudo(
                    $server,
                    'docker rm -f '.RemoteShell::quote($container).' >/dev/null 2>&1 || true; '.$run
                ),
                120
            );

            $this->executor->upload($server, $localRoute, $temporaryRoute);
            $this->executor->execute(
                $server,
                $this->sudo(
                    $server,
                    'install -m 0640 '.RemoteShell::quote($temporaryRoute).' '.RemoteShell::quote($remoteRoute)
                    .' && rm -f '.RemoteShell::quote($temporaryRoute)
                ),
                60
            );
        } finally {
            @unlink($localHtml);
            @unlink($localRoute);
        }
    }

    public function disable(Server $server): void
    {
        if (config('infrastructure.driver') === 'fake') {
            return;
        }

        $container = (string) config('networking.maintenance_container', 'uplary-maintenance');
        $dynamic = rtrim((string) config('networking.proxy_dynamic_path'), '/');
        $route = $dynamic.'/maintenance.yml';

        try {
            $this->executor->execute(
                $server,
                $this->sudo(
                    $server,
                    'rm -f '.RemoteShell::quote($route)
                    .'; docker rm -f '.RemoteShell::quote($container).' >/dev/null 2>&1 || true'
                ),
                60
            );
        } catch (Throwable) {
            // Recovery should still mark the server online even if cleanup fails.
        }
    }

    private function maintenanceRouteConfiguration(string $container): string
    {
        $serviceUrl = 'http://'.$container.':80';

        return "http:\n"
            ."  routers:\n"
            ."    uplary-maintenance-web:\n"
            ."      rule: \"HostRegexp(`{host:.+}`)\"\n"
            ."      priority: 10000\n"
            ."      entryPoints: [web]\n"
            ."      service: uplary-maintenance\n"
            ."    uplary-maintenance-secure:\n"
            ."      rule: \"HostRegexp(`{host:.+}`)\"\n"
            ."      priority: 10000\n"
            ."      entryPoints: [websecure]\n"
            ."      service: uplary-maintenance\n"
            ."      tls: {}\n"
            ."  services:\n"
            ."    uplary-maintenance:\n"
            ."      loadBalancer:\n"
            ."        servers:\n"
            ."          - url: \"{$serviceUrl}\"\n";
    }

    private function sudo(Server $server, string $command): string
    {
        return strcasecmp((string) $server->ssh_username, 'root') === 0
            ? $command
            : 'sudo -n sh -c '.RemoteShell::quote($command);
    }
}
