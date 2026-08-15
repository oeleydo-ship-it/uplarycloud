<?php

namespace App\Services\Deployments;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ContainerStatus;
use App\Enums\DeploymentStatus;
use App\Exceptions\RemoteCommandException;
use App\Models\ApplicationDeployment;
use App\Models\DeploymentRelease;
use App\Models\DockerContainer;
use App\Models\DockerImage;
use App\Models\DockerNetwork;
use App\Models\DockerVolume;
use App\Models\Domain;
use App\Services\Networking\DomainNetworkService;
use App\Services\Servers\ServerProvisionVerifier;
use App\Support\RemoteShell;
use RuntimeException;
use Throwable;

class DeploymentService
{
    public const STAGES = [
        'prepare' => 'Preparing Server', 'pull_image' => 'Pulling Docker Image', 'create_network' => 'Creating Network',
        'create_volumes' => 'Creating Volumes', 'create_environment' => 'Creating Environment', 'create_containers' => 'Creating Containers',
        'start_services' => 'Starting Services', 'health_check' => 'Health Check', 'configure_domain' => 'Configuring Domain',
        'issue_ssl' => 'Issuing SSL', 'complete' => 'Deployment Complete',
    ];

    /** @var array<int, int> Host port chosen for each deployment during create_containers. */
    private array $hostPorts = [];

    public function __construct(
        private readonly ServerExecutorInterface $executor,
        private readonly DomainNetworkService $networking,
        private readonly DeploymentContainerVerifier $containers,
        private readonly ServerProvisionVerifier $provisionVerifier,
    ) {}

    public function execute(ApplicationDeployment $deployment, string $stage): void
    {
        match ($stage) {
            'prepare' => $this->prepare($deployment),
            'pull_image' => $this->pullImage($deployment),
            'create_network' => $this->command($deployment, 'docker network inspect '.RemoteShell::quote($this->networkName($deployment)).' >/dev/null 2>&1 || docker network create '.RemoteShell::quote($this->networkName($deployment))),
            'create_volumes' => $this->createVolumes($deployment),
            'create_environment' => $this->createEnvironment($deployment),
            'create_containers' => $this->createContainer($deployment),
            'start_services' => $this->startServices($deployment),
            'health_check' => $this->healthCheck($deployment),
            'configure_domain' => $this->configureDomain($deployment),
            'issue_ssl' => $this->issueSsl($deployment),
            'complete' => $this->complete($deployment),
            default => throw new RuntimeException('Unknown deployment stage.'),
        };
    }

    public function rollback(ApplicationDeployment $deployment, DeploymentRelease $release): void
    {
        if ($release->application_deployment_id !== $deployment->id) {
            throw new RuntimeException('Release does not belong to this application.');
        }
        $deployment->update(['status' => DeploymentStatus::RollingBack, 'current_stage' => 'rollback', 'progress' => 25, 'last_error' => null]);
        $this->log($deployment, 'warning', 'Rolling back to release '.$release->version.' ('.$release->image_tag.').');
        $deployment->update(['docker_image' => $release->image, 'docker_tag' => $release->image_tag]);
        $this->execute($deployment, 'pull_image');
        $this->execute($deployment, 'create_containers');
        $this->execute($deployment, 'start_services');
        $this->execute($deployment, 'health_check');
        $deployment->releases()->update(['is_current' => false]);
        $release->update(['is_current' => true, 'status' => 'successful', 'rolled_back_at' => now()]);
        $deployment->update(['status' => DeploymentStatus::Running, 'progress' => 100, 'current_stage' => 'complete', 'completed_at' => now(), 'deployed_at' => now()]);
        $this->log($deployment, 'success', 'Rollback completed successfully.');
    }

    /**
     * Re-check a previously "running" deployment against the live host.
     *
     * @return array{ok: bool, status: ?string, health: ?string, message: string}
     */
    public function verifyRuntime(ApplicationDeployment $deployment): array
    {
        $server = $deployment->server()->withTrashed()->first();
        if (! $server || $server->trashed()) {
            return [
                'ok' => false,
                'status' => null,
                'health' => null,
                'message' => 'The selected server is no longer available.',
            ];
        }

        $result = $this->containers->inspect($server, $deployment->slug);

        $settled = in_array($deployment->status, [DeploymentStatus::Running, DeploymentStatus::Failed], true);

        if (! $result['ok'] && $settled) {
            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'last_error' => $result['message'],
                'completed_at' => now(),
            ]);
            $deployment->containers()->update(['status' => ContainerStatus::Exited, 'health' => 'unhealthy']);
            $this->discardCurrentRelease($deployment, $result['message']);
            $deployment->steps()->whereIn('key', ['health_check', 'complete'])->update([
                'status' => 'failed',
                'error' => $result['message'],
                'completed_at' => now(),
            ]);
            $this->log($deployment, 'error', $result['message']);
        }

        if ($result['ok']) {
            $this->log($deployment, 'success', $result['message']);
        }

        return $result;
    }

    public function log(ApplicationDeployment $deployment, string $level, string $message, array $context = []): void
    {
        $deployment->logs()->create(compact('level', 'message', 'context') + ['occurred_at' => now()]);
    }

    private function prepare(ApplicationDeployment $deployment): void
    {
        $server = $deployment->server()->withTrashed()->first();
        if (! $server) {
            throw new RuntimeException('The selected server is no longer available. Remove this deployment from the control plane or reattach a server.');
        }
        if ($server->trashed()) {
            throw new RuntimeException('The selected server was removed from the control plane and cannot accept deployments.');
        }
        if ($server->status->value !== 'online') {
            throw new RuntimeException('The selected server is not online.');
        }
        if ($deployment->memory_limit_mb && $server->memory_mb < $deployment->memory_limit_mb) {
            throw new RuntimeException('The server does not have enough memory.');
        }
        if ($deployment->disk_limit_gb && $server->disk_gb < $deployment->disk_limit_gb) {
            throw new RuntimeException('The server does not have enough disk space.');
        }

        $this->guardDriver($deployment);

        if (config('infrastructure.driver') === 'ssh') {
            $this->executor->ensureReady($server);
            $this->log($deployment, 'success', 'Secure SSH connection verified on '.$server->ip_address.' via '.class_basename($this->executor).'.');
        } else {
            $this->log($deployment, 'warning', 'Simulated infrastructure driver — Docker commands will not run on the host.');
        }
    }

    private function guardDriver(ApplicationDeployment $deployment): void
    {
        if (config('infrastructure.driver') === 'ssh') {
            return;
        }

        if ($this->provisionVerifier->allowsSimulatedProvisioning($deployment->server->ip_address)) {
            return;
        }

        throw new RuntimeException(
            'Live hosts require INFRASTRUCTURE_DRIVER=ssh. The fake driver cannot install applications on public servers.'
        );
    }

    private function createVolumes(ApplicationDeployment $deployment): void
    {
        $name = $deployment->slug.'-data';
        $this->command($deployment, 'docker volume inspect '.RemoteShell::quote($name).' >/dev/null 2>&1 || docker volume create '.RemoteShell::quote($name));

        if ($this->managedDatabaseSpec($deployment)) {
            $dbVolume = $deployment->slug.'-db';
            $this->command($deployment, 'docker volume inspect '.RemoteShell::quote($dbVolume).' >/dev/null 2>&1 || docker volume create '.RemoteShell::quote($dbVolume));
        }
    }

    private function createEnvironment(ApplicationDeployment $deployment): void
    {
        $this->log($deployment, 'success', $deployment->environmentVariables()->count().' encrypted environment variables prepared.');
    }

    private function pullImage(ApplicationDeployment $deployment): void
    {
        $reference = $deployment->docker_image.':'.$deployment->docker_tag;
        $this->command($deployment, 'docker image pull '.RemoteShell::quote($reference), $this->timeout('pull'));
        $this->log($deployment, 'success', 'Image '.$reference.' is present on the server.');

        if ($spec = $this->managedDatabaseSpec($deployment)) {
            $this->command($deployment, 'docker image pull '.RemoteShell::quote($spec['image']), $this->timeout('pull'));
            $this->log($deployment, 'success', 'Database image '.$spec['image'].' is present on the server.');
        }
    }

    private function createContainer(ApplicationDeployment $deployment): void
    {
        $deployment->loadMissing(['environmentVariables', 'application']);

        if ($spec = $this->managedDatabaseSpec($deployment)) {
            $this->createDatabaseSidecar($deployment, $spec);
        }

        $this->command($deployment, 'docker rm -f '.RemoteShell::quote($deployment->slug).' >/dev/null 2>&1 || true');
        $env = '';
        foreach ($deployment->environmentVariables()->get() as $variable) {
            $env .= ' --env '.RemoteShell::quote($variable->key.'='.$variable->value);
        }
        $port = $this->publishFlag($deployment);
        $limits = ($deployment->memory_limit_mb ? ' --memory '.RemoteShell::quote($deployment->memory_limit_mb.'m') : '').($deployment->cpu_limit ? ' --cpus '.RemoteShell::quote((string) $deployment->cpu_limit) : '');
        $dataMount = $this->applicationDataMount($deployment);
        $commandArgs = $this->applicationCommandArgs($deployment);
        $command = 'docker create --name '.RemoteShell::quote($deployment->slug).' --network '.RemoteShell::quote($this->networkName($deployment)).' --restart '.RemoteShell::quote($deployment->restart_policy).' -v '.RemoteShell::quote($deployment->slug.'-data:'.$dataMount).$port.$limits.$env.' '.RemoteShell::quote($deployment->docker_image.':'.$deployment->docker_tag).$commandArgs;
        $this->log($deployment, 'info', 'Creating container: '.RemoteCommandException::redact($command));
        $this->command($deployment, $command);
        $this->assertContainerExists($deployment);
    }

    /**
     * Catalog apps default DB hosts to "db" — provision MariaDB on the app network.
     * Supports WordPress/Joomla/Backdrop (*_DB_HOST), PrestaShop (DB_SERVER),
     * Concrete/Matomo (MYSQL_HOST / MATOMO_DATABASE_HOST), and BookStack-style DB_HOST.
     *
     * @return array{image:string,container:string,database:string,user:string,password:string,root_password:string,host_keys:list<string>}|null
     */
    private function managedDatabaseSpec(ApplicationDeployment $deployment): ?array
    {
        $deployment->loadMissing(['environmentVariables', 'application']);
        $env = $deployment->environmentVariables->keyBy('key');
        $slug = strtolower((string) ($deployment->application?->slug ?: ''));
        $image = strtolower((string) $deployment->docker_image);

        foreach (['WORDPRESS', 'JOOMLA', 'BACKDROP'] as $prefix) {
            $hostKey = $prefix.'_DB_HOST';
            $matchesSlug = $prefix === 'WORDPRESS' && ($slug === 'wordpress' || str_contains($image, 'wordpress'));
            if (! $env->has($hostKey) && ! $matchesSlug) {
                continue;
            }

            $host = strtolower(trim((string) ($env->get($hostKey)?->value ?? 'db')));
            if (! $this->isManagedDbHostname($host, $deployment->slug)) {
                return null;
            }

            $password = (string) ($env->get($prefix.'_DB_PASSWORD')?->value ?? '');
            if ($password === '') {
                return null;
            }

            $defaultName = strtolower($prefix) === 'wordpress' ? 'wordpress' : strtolower($prefix);

            return [
                'image' => 'mariadb:11',
                'container' => $deployment->slug.'-db',
                'database' => (string) ($env->get($prefix.'_DB_NAME')?->value ?: $defaultName),
                'user' => (string) ($env->get($prefix.'_DB_USER')?->value ?: $defaultName),
                'password' => $password,
                'root_password' => $password,
                'host_keys' => [$hostKey],
            ];
        }

        if ($env->has('DB_SERVER')) {
            $host = strtolower(trim((string) ($env->get('DB_SERVER')?->value ?? '')));
            if (! $this->isManagedDbHostname($host, $deployment->slug)) {
                return null;
            }
            $password = (string) ($env->get('DB_PASSWD')?->value ?? $env->get('DB_PASSWORD')?->value ?? '');
            if ($password === '') {
                return null;
            }

            return [
                'image' => 'mariadb:11',
                'container' => $deployment->slug.'-db',
                'database' => (string) ($env->get('DB_NAME')?->value ?: 'prestashop'),
                'user' => (string) ($env->get('DB_USER')?->value ?: 'prestashop'),
                'password' => $password,
                'root_password' => $password,
                'host_keys' => ['DB_SERVER'],
            ];
        }

        if ($env->has('MYSQL_HOST')) {
            $host = strtolower(trim((string) ($env->get('MYSQL_HOST')?->value ?? '')));
            if (! $this->isManagedDbHostname($host, $deployment->slug)) {
                return null;
            }
            $password = (string) ($env->get('MYSQL_PASSWORD')?->value ?? '');
            if ($password === '') {
                return null;
            }

            return [
                'image' => 'mariadb:11',
                'container' => $deployment->slug.'-db',
                'database' => (string) ($env->get('MYSQL_DATABASE')?->value ?: 'app'),
                'user' => (string) ($env->get('MYSQL_USER')?->value ?: 'app'),
                'password' => $password,
                'root_password' => $password,
                'host_keys' => ['MYSQL_HOST'],
            ];
        }

        if ($env->has('MATOMO_DATABASE_HOST')) {
            $host = strtolower(trim((string) ($env->get('MATOMO_DATABASE_HOST')?->value ?? '')));
            if (! $this->isManagedDbHostname($host, $deployment->slug)) {
                return null;
            }
            $password = (string) ($env->get('MATOMO_DATABASE_PASSWORD')?->value ?? '');
            if ($password === '') {
                return null;
            }

            return [
                'image' => 'mariadb:11',
                'container' => $deployment->slug.'-db',
                'database' => (string) ($env->get('MATOMO_DATABASE_DBNAME')?->value ?: 'matomo'),
                'user' => (string) ($env->get('MATOMO_DATABASE_USERNAME')?->value ?: 'matomo'),
                'password' => $password,
                'root_password' => $password,
                'host_keys' => ['MATOMO_DATABASE_HOST'],
            ];
        }

        if ($env->has('CRAFT_DB_SERVER')) {
            $host = strtolower(trim((string) ($env->get('CRAFT_DB_SERVER')?->value ?? '')));
            if (! $this->isManagedDbHostname($host, $deployment->slug)) {
                return null;
            }
            $password = (string) ($env->get('CRAFT_DB_PASSWORD')?->value ?? '');
            if ($password === '') {
                return null;
            }

            return [
                'image' => 'mariadb:11',
                'container' => $deployment->slug.'-db',
                'database' => (string) ($env->get('CRAFT_DB_DATABASE')?->value ?: 'craft'),
                'user' => (string) ($env->get('CRAFT_DB_USER')?->value ?: 'craft'),
                'password' => $password,
                'root_password' => $password,
                'host_keys' => ['CRAFT_DB_SERVER'],
            ];
        }

        if ($env->has('DB_HOST') && ($slug === 'bookstack' || $env->has('DB_DATABASE') || $env->has('DB_NAME'))) {
            $host = strtolower(trim((string) ($env->get('DB_HOST')?->value ?? '')));
            if (! $this->isManagedDbHostname($host, $deployment->slug)) {
                return null;
            }
            $password = (string) ($env->get('DB_PASSWORD')?->value ?? $env->get('DB_PASS')?->value ?? '');
            if ($password === '') {
                return null;
            }

            return [
                'image' => 'mariadb:11',
                'container' => $deployment->slug.'-db',
                'database' => (string) ($env->get('DB_DATABASE')?->value ?? $env->get('DB_NAME')?->value ?: 'app'),
                'user' => (string) ($env->get('DB_USERNAME')?->value ?? $env->get('DB_USER')?->value ?: 'app'),
                'password' => $password,
                'root_password' => $password,
                'host_keys' => ['DB_HOST'],
            ];
        }

        return null;
    }

    private function applicationCommandArgs(ApplicationDeployment $deployment): string
    {
        $slug = strtolower((string) ($deployment->application?->slug ?: ''));

        return match ($slug) {
            'minio' => ' server /data --console-address ":9001"',
            'keycloak' => ' start-dev',
            'openclaw' => ' sh -lc '.RemoteShell::quote(
                'set -eu; '
                .'if [ ! -s /home/node/.openclaw/openclaw.json ]; then '
                .'node dist/index.js onboard --non-interactive --accept-risk --skip-health --mode local --auth-choice skip --gateway-auth token --gateway-token-ref-env OPENCLAW_GATEWAY_TOKEN --skip-channels --no-install-daemon; '
                .'fi; '
                .'ORIGIN="${OPENCLAW_PUBLIC_ORIGIN:-http://127.0.0.1:18789}"; '
                .'node dist/index.js config set --batch-json "[{\"path\":\"gateway.mode\",\"value\":\"local\"},{\"path\":\"gateway.bind\",\"value\":\"lan\"},{\"path\":\"gateway.controlUi.allowedOrigins\",\"value\":[\"$ORIGIN\",\"http://127.0.0.1:18789\",\"http://localhost:18789\"]}]"; '
                .'exec node dist/index.js gateway --bind lan --port 18789'
            ),
            default => '',
        };
    }

    private function isManagedDbHostname(string $host, string $slug): bool
    {
        return $host === '' || in_array($host, ['db', 'mysql', 'mariadb', strtolower($slug).'-db'], true);
    }

    /**
     * @param  array{image:string,container:string,database:string,user:string,password:string,root_password:string,host_keys:list<string>}  $spec
     */
    private function createDatabaseSidecar(ApplicationDeployment $deployment, array $spec): void
    {
        $network = $this->networkName($deployment);
        $container = $spec['container'];

        foreach ($spec['host_keys'] as $key) {
            $variable = $deployment->environmentVariables->firstWhere('key', $key);
            if (! $variable) {
                $variable = $deployment->environmentVariables()->create([
                    'key' => $key,
                    'value' => $container,
                    'secret' => false,
                ]);
            } elseif ($this->isManagedDbHostname(strtolower(trim((string) $variable->value)), $deployment->slug)) {
                $variable->value = $container;
                $variable->save();
            }
        }
        $deployment->unsetRelation('environmentVariables');
        $deployment->load('environmentVariables');

        // Reuse a healthy DB sidecar on redeploy so named volume data is not interrupted.
        if ($this->containerIsRunning($deployment, $container)) {
            $this->log($deployment, 'success', 'Managed database sidecar '.$container.' already running; reusing volume '.$deployment->slug.'-db.');

            return;
        }

        $this->command($deployment, 'docker rm -f '.RemoteShell::quote($container).' >/dev/null 2>&1 || true');

        $dbEnv = ' --env '.RemoteShell::quote('MYSQL_DATABASE='.$spec['database'])
            .' --env '.RemoteShell::quote('MYSQL_USER='.$spec['user'])
            .' --env '.RemoteShell::quote('MYSQL_PASSWORD='.$spec['password'])
            .' --env '.RemoteShell::quote('MYSQL_ROOT_PASSWORD='.$spec['root_password']);

        $command = 'docker create --name '.RemoteShell::quote($container)
            .' --network '.RemoteShell::quote($network)
            .' --restart '.RemoteShell::quote($deployment->restart_policy ?: 'unless-stopped')
            .' -v '.RemoteShell::quote($deployment->slug.'-db:/var/lib/mysql')
            .$dbEnv.' '.RemoteShell::quote($spec['image']);

        $this->log($deployment, 'info', 'Creating managed database sidecar '.$container.' (volume '.$deployment->slug.'-db preserved).');
        $this->command($deployment, $command);
    }

    private function containerIsRunning(ApplicationDeployment $deployment, string $container): bool
    {
        if (config('infrastructure.driver') === 'fake') {
            return false;
        }

        try {
            $status = trim($this->executor->execute(
                $deployment->server,
                'docker inspect -f {{.State.Running}} '.RemoteShell::quote($container).' 2>/dev/null || true',
                20
            ));

            return $status === 'true';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Docker refuses to bind a host port that another container (for example the
     * platform proxy on :80) already owns, so pick a free one and remember it.
     */
    private function publishFlag(ApplicationDeployment $deployment): string
    {
        $containerPort = (int) $deployment->container_port;
        if ($containerPort <= 0) {
            return '';
        }

        if ($deployment->domain) {
            // Traefik owns 80/443 and reaches the container over the shared proxy
            // network, so binding a host port would only collide with it.
            $this->log($deployment, 'info', 'Traffic for '.$deployment->domain.' is routed through the proxy; no host port is published.');

            return '';
        }

        $hostPort = $this->resolveHostPort($deployment, $containerPort);
        $this->hostPorts[$deployment->id] = $hostPort;

        if ($hostPort !== $containerPort) {
            $this->log(
                $deployment,
                'warning',
                'Host port '.$containerPort.' is already in use on the server; publishing the container on '.$hostPort.' instead.'
            );
        }

        return ' --publish '.RemoteShell::quote($hostPort.':'.$containerPort);
    }

    private function resolveHostPort(ApplicationDeployment $deployment, int $containerPort): int
    {
        $used = $this->listeningPorts($deployment);

        if (! in_array($containerPort, $used, true)) {
            return $containerPort;
        }

        for ($candidate = 8080; $candidate <= 8999; $candidate++) {
            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No free host port is available on the server to publish '.$containerPort.'.');
    }

    /** @return list<int> */
    private function listeningPorts(ApplicationDeployment $deployment): array
    {
        try {
            $output = $this->executor->execute(
                $deployment->server,
                'ss -H -ltn 2>/dev/null | awk \'{print $4}\' | sed \'s/.*://\' | sort -un'
            );
        } catch (Throwable) {
            return [];
        }

        $ports = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ctype_digit($line)) {
                $ports[] = (int) $line;
            }
        }

        return $ports;
    }

    private function assertContainerExists(ApplicationDeployment $deployment): void
    {
        $this->executor->execute(
            $deployment->server,
            'docker inspect --format '.RemoteShell::quote('{{.Id}}').' '.RemoteShell::quote($deployment->slug)
        );
    }

    private function startServices(ApplicationDeployment $deployment): void
    {
        try {
            if ($spec = $this->managedDatabaseSpec($deployment)) {
                $this->command($deployment, 'docker start '.RemoteShell::quote($spec['container']));
                $this->waitForDatabase($deployment, $spec);
                $this->log($deployment, 'success', 'Database sidecar '.$spec['container'].' is accepting connections.');
            }
            $this->command($deployment, 'docker start '.RemoteShell::quote($deployment->slug));
        } catch (Throwable $exception) {
            $this->logContainerDiagnostics($deployment);

            throw $exception;
        }
    }

    /**
     * @param  array{container:string,user:string,password:string,database:string}  $spec
     */
    private function waitForDatabase(ApplicationDeployment $deployment, array $spec): void
    {
        // Prefer MYSQL_PWD so passwords with |, $, & etc. cannot break -p parsing.
        // MariaDB 11 images ship mariadb-admin (mysqladmin may be absent).
        $ping = 'docker exec -e '.RemoteShell::quote('MYSQL_PWD='.$spec['password']).' '
            .RemoteShell::quote($spec['container'])
            .' sh -lc '.RemoteShell::quote('mariadb-admin ping -h 127.0.0.1 -uroot --silent || mysqladmin ping -h 127.0.0.1 -uroot --silent');

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            try {
                $this->executor->execute($deployment->server, $ping, 20);

                return;
            } catch (Throwable) {
                if ($attempt === 30) {
                    break;
                }
                sleep(2);
            }
        }

        throw new RuntimeException('Managed database sidecar '.$spec['container'].' did not become ready in time.');
    }

    private function healthCheck(ApplicationDeployment $deployment): void
    {
        try {
            $this->containers->assertRunning($deployment->server, $deployment->slug);
        } catch (Throwable $exception) {
            $this->logContainerDiagnostics($deployment);

            throw $exception;
        }

        $this->log($deployment, 'success', 'Container '.$deployment->slug.' verified running on the server.');
    }

    /**
     * Pull whatever the engine can still tell us about a container that failed to run.
     */
    private function logContainerDiagnostics(ApplicationDeployment $deployment): void
    {
        foreach ([
            'exit' => 'docker inspect --format '.RemoteShell::quote('exit={{.State.ExitCode}} error={{.State.Error}}').' '.RemoteShell::quote($deployment->slug),
            'logs' => 'docker logs --tail 40 '.RemoteShell::quote($deployment->slug).' 2>&1',
        ] as $label => $command) {
            try {
                $output = trim($this->executor->execute($deployment->server, $command, 60));
            } catch (Throwable) {
                continue;
            }

            if ($output !== '') {
                $this->log($deployment, 'error', 'Container '.$label.': '.str($output)->limit(2000));
            }
        }
    }

    private function configureDomain(ApplicationDeployment $deployment): void
    {
        if (! $deployment->domain) {
            return;
        }

        $hostname = strtolower($deployment->domain);
        $existing = Domain::where('hostname', $hostname)->first();

        if ($existing && $existing->application_deployment_id !== $deployment->id) {
            if ($this->canReclaimDomain($existing, $deployment)) {
                $existing->update([
                    'application_deployment_id' => $deployment->id,
                    'server_id' => $deployment->server_id,
                    'expected_value' => $deployment->server->ip_address,
                ]);
                $this->log($deployment, 'info', 'Reassigned '.$hostname.' from a previous deployment.');
            } else {
                $holder = ApplicationDeployment::find($existing->application_deployment_id);
                $holderName = $holder?->name ?? 'another application';
                $this->log(
                    $deployment,
                    'warning',
                    $hostname.' is already assigned to '.$holderName.'. The application is running; remove or reassign the domain in Domains to switch routing.'
                );

                return;
            }
        }

        $domain = $existing ?? Domain::create([
            'tenant_id' => $deployment->tenant_id,
            'application_deployment_id' => $deployment->id,
            'server_id' => $deployment->server_id,
            'created_by' => $deployment->created_by,
            'hostname' => $hostname,
            'expected_value' => $deployment->server->ip_address,
            'force_https' => true,
            'ssl_enabled' => true,
            'auto_renew' => true,
        ]);

        if ($this->networking->verifyDns($domain)) {
            $this->networking->configure($domain);
        } else {
            $this->log($deployment, 'warning', 'Domain saved; waiting for DNS to point to '.$domain->expected_value.'.');
        }
    }

    private function canReclaimDomain(Domain $existing, ApplicationDeployment $deployment): bool
    {
        if ($existing->tenant_id !== $deployment->tenant_id) {
            return false;
        }

        $previous = ApplicationDeployment::withTrashed()->find($existing->application_deployment_id);
        if ($previous === null) {
            return true;
        }

        if ($previous->trashed()) {
            return true;
        }

        if (
            $deployment->application_id
            && $previous->application_id === $deployment->application_id
        ) {
            return true;
        }

        $status = $previous->status;
        $value = $status instanceof DeploymentStatus ? $status->value : (string) $status;

        return in_array($value, [DeploymentStatus::Failed->value, DeploymentStatus::Stopped->value], true);
    }

    private function issueSsl(ApplicationDeployment $deployment): void
    {
        if (! $deployment->domain) {
            return;
        }
        $domain = Domain::where('application_deployment_id', $deployment->id)->where('hostname', strtolower($deployment->domain))->first();
        if ($domain?->proxy_status !== 'configured') {
            return;
        }

        try {
            $this->networking->issueCertificate($domain);
        } catch (RuntimeException $exception) {
            // The application itself is healthy; a pending certificate should not
            // roll back the deployment, and the proxy keeps retrying ACME.
            $domain->update(['ssl_status' => 'pending', 'failure_reason' => $exception->getMessage()]);
            $this->log($deployment, 'warning', 'HTTPS is not active yet. '.$exception->getMessage());
        }
    }

    private function complete(ApplicationDeployment $deployment): void
    {
        // Final gate: never mark inventory / release successful unless the container is still up.
        $this->containers->assertRunning($deployment->server, $deployment->slug, 30);

        DockerImage::updateOrCreate(
            ['tenant_id' => $deployment->tenant_id, 'server_id' => $deployment->server_id, 'repository' => $deployment->docker_image, 'tag' => $deployment->docker_tag],
            ['docker_id' => 'sha256:'.substr(hash('sha256', $deployment->docker_image.$deployment->docker_tag), 0, 12), 'status' => 'available', 'used_by_count' => 1, 'pulled_at' => now()]
        );
        $network = DockerNetwork::firstOrCreate(
            ['tenant_id' => $deployment->tenant_id, 'server_id' => $deployment->server_id, 'name' => $this->networkName($deployment)],
            ['docker_id' => substr(hash('sha256', $this->networkName($deployment)), 0, 12), 'driver' => 'bridge', 'internal' => true, 'attachable' => true]
        );
        $volume = DockerVolume::firstOrCreate(
            ['tenant_id' => $deployment->tenant_id, 'server_id' => $deployment->server_id, 'docker_name' => $deployment->slug.'-data'],
            ['name' => $deployment->name.' Data', 'mountpoint' => '/var/lib/docker/volumes/'.$deployment->slug.'-data']
        );
        $container = DockerContainer::withTrashed()->updateOrCreate(
            ['tenant_id' => $deployment->tenant_id, 'server_id' => $deployment->server_id, 'name' => $deployment->slug],
            [
                'application_deployment_id' => $deployment->id,
                'docker_id' => substr(hash('sha256', $deployment->uuid), 0, 12),
                'image' => $deployment->docker_image.':'.$deployment->docker_tag,
                'status' => ContainerStatus::Running,
                'health' => 'healthy',
                'ports' => $deployment->container_port ? [['private' => $deployment->container_port, 'public' => $this->hostPorts[$deployment->id] ?? $deployment->container_port]] : [],
                'memory_limit_mb' => $deployment->memory_limit_mb,
                'started_at' => now(),
                'deleted_at' => null,
            ]
        );
        $container->networks()->syncWithoutDetaching([$network->id => ['ip_address' => null]]);
        $container->volumes()->syncWithoutDetaching([$volume->id => ['mount_path' => '/data']]);
        $deployment->releases()->update(['is_current' => false]);
        $deployment->releases()->create([
            'version' => 'v'.now()->format('Ymd.His'),
            'image' => $deployment->docker_image,
            'image_tag' => $deployment->docker_tag,
            'status' => 'successful',
            'is_current' => true,
            'configuration' => ['cpu_limit' => $deployment->cpu_limit, 'memory_limit_mb' => $deployment->memory_limit_mb, 'restart_policy' => $deployment->restart_policy],
            'deployed_at' => now(),
        ]);
        $deployment->application?->increment('install_count');
    }

    /**
     * Run a command on the host. Any non-zero exit throws so the current stage
     * fails instead of silently continuing, and the real stderr reaches the log.
     */
    private function command(ApplicationDeployment $deployment, string $command, ?int $timeoutSeconds = null): void
    {
        try {
            // Always invoke the bound executor so SSH pulls/creates/starts for real,
            // and the fake executor can still simulate inspect/status for tests.
            $this->executor->execute($deployment->server, $command, $timeoutSeconds ?? $this->timeout('default'));
        } catch (RemoteCommandException $exception) {
            $this->log($deployment, 'error', 'Command failed: '.$exception->redactedCommand());
            if ($exception->detail() !== '') {
                $this->log($deployment, 'error', 'Docker said: '.$exception->detail());
            }

            throw $exception;
        }
    }

    private function timeout(string $key): int
    {
        return max(1, (int) config('infrastructure.command_timeouts.'.$key, 180));
    }

    private function discardCurrentRelease(ApplicationDeployment $deployment, string $reason): void
    {
        $discarded = $deployment->releases()->where('is_current', true)->update([
            'is_current' => false,
            'status' => 'failed',
        ]);

        if ($discarded > 0) {
            $this->log($deployment, 'warning', 'Release marked failed because the runtime check did not pass: '.$reason);
        }
    }

    private function applicationDataMount(ApplicationDeployment $deployment): string
    {
        $slug = strtolower((string) ($deployment->application?->slug ?: ''));
        $image = strtolower((string) $deployment->docker_image);

        if ($slug === 'wordpress' || str_contains($image, 'wordpress')) {
            return '/var/www/html';
        }

        $deployment->loadMissing(['application.template']);
        $volumes = $deployment->application?->template?->volume_schema;
        if (is_array($volumes)) {
            foreach ($volumes as $volume) {
                $path = is_array($volume) ? (string) ($volume['path'] ?? '') : '';
                if ($path !== '') {
                    return $path;
                }
            }
        }

        return '/data';
    }

    private function networkName(ApplicationDeployment $deployment): string
    {
        return $deployment->slug.'-network';
    }
}
