<?php

namespace App\Services\Deployments;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Exceptions\RemoteCommandException;
use App\Models\ApplicationDeployment;
use App\Models\DeploymentEnvironmentVariable;
use App\Support\PlatformPaths;
use App\Support\RemoteShell;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WebApplicationDeploymentService
{
    public const STAGES = [
        'clone_source' => 'Cloning Repository',
        'detect_buildpack' => 'Detecting Build Pack',
        'install_dependencies' => 'Installing Dependencies',
        'build_assets' => 'Building Application',
        'build_image' => 'Building Docker Image',
        'create_services' => 'Creating Runtime Services',
        'run_migrations' => 'Running Migrations',
        'start_application' => 'Starting Application',
        'health_check' => 'Health Check',
        'configure_domain' => 'Configuring Domain',
        'issue_ssl' => 'Issuing SSL',
        'complete' => 'Deployment Complete',
    ];

    public function __construct(
        private readonly ServerExecutorInterface $executor,
        private readonly DeploymentService $deployments,
    ) {}

    public function execute(ApplicationDeployment $deployment, string $stage): void
    {
        match ($stage) {
            'clone_source' => $this->clone($deployment),
            'detect_buildpack' => $this->detect($deployment),
            'install_dependencies' => $this->log($deployment, 'Dependencies will install inside the image with: '.$deployment->install_command),
            'build_assets' => $this->log($deployment, 'Frontend assets compile during image build when package.json is present (npm run build / Vite).'),
            'build_image' => $this->buildImage($deployment),
            'create_services' => $this->services($deployment),
            'run_migrations' => $this->migrate($deployment),
            'start_application' => $this->start($deployment),
            'health_check' => $this->deployments->execute($deployment, 'health_check'),
            'configure_domain' => $this->deployments->execute($deployment, 'configure_domain'),
            'issue_ssl' => $this->deployments->execute($deployment, 'issue_ssl'),
            'complete' => $this->complete($deployment),
            default => throw new RuntimeException('Unknown web deployment stage.'),
        };
    }

    private function clone(ApplicationDeployment $d): void
    {
        // Reuse marketplace prepare gates (online server, fake-driver public IP refusal, SSH test).
        $this->deployments->execute($d, 'prepare');

        if (config('infrastructure.driver') === 'fake') {
            $d->update(['commit_hash' => $d->commit_hash ?: substr(hash('sha1', $d->repository_url.now()), 0, 40)]);

            return;
        }

        $dir = $this->buildDirectory($d);
        $this->command($d, PlatformPaths::ensureTreeCommandFor($d->server));
        $keyOption = '';
        if ($d->deploy_key) {
            $local = storage_path('app/private/deploy-key-'.$d->uuid);
            if (! is_dir(dirname($local))) {
                mkdir(dirname($local), 0750, true);
            }
            file_put_contents($local, $d->deploy_key, LOCK_EX);
            try {
                $remote = PlatformPaths::keys().'/'.$d->uuid;
                $this->command($d, 'install -d -m 0700 '.RemoteShell::quote(PlatformPaths::keys()));
                $this->executor->upload($d->server, $local, $remote);
                $this->command($d, 'chmod 600 '.RemoteShell::quote($remote));
                $keyOption = 'GIT_SSH_COMMAND='.RemoteShell::quote('ssh -i '.$remote.' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new').' ';
            } finally {
                @unlink($local);
            }
        }

        $this->log($d, 'Cloning '.$d->repository_url.' ('.$d->branch.')…');
        $cloneBody = $keyOption
            .'git -c http.version=HTTP/1.1 -c http.postBuffer=524288000 clone --depth 1 --branch '
            .RemoteShell::quote($d->branch).' '
            .RemoteShell::quote($d->repository_url).' '
            .RemoteShell::quote($dir);
        $attempts = 3;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1) {
                $this->deployments->log($d, 'warning', 'Git clone failed; retrying (attempt '.$attempt.' of '.$attempts.')…');
            }

            try {
                $this->command(
                    $d,
                    'install -d -m 0755 '.RemoteShell::quote(PlatformPaths::builds())
                    .' && rm -rf '.RemoteShell::quote($dir)
                    .' && '.$cloneBody,
                    'clone'
                );
                break;
            } catch (RemoteCommandException $exception) {
                if ($attempt >= $attempts || ! $this->isRetryableGitCloneFailure($exception)) {
                    throw $exception;
                }
            }
        }
        $result = $this->executor->execute($d->server, 'git -C '.RemoteShell::quote($dir).' rev-parse HEAD', $this->timeout('default'));
        $d->update(['commit_hash' => trim($result)]);
        $this->log($d, 'Checked out commit '.substr($d->commit_hash, 0, 12).'.');
    }

    private function detect(ApplicationDeployment $d): void
    {
        if (! $d->buildPack?->active) {
            throw new RuntimeException('The selected build pack is unavailable.');
        }

        // Laravel apps need a database sidecar unless the operator explicitly chose none.
        if ($d->framework === 'laravel' && blank($d->database_engine)) {
            $d->update(['database_engine' => 'mysql']);
            $this->log($d, 'Defaulted Laravel database sidecar to MySQL/MariaDB.');
        }

        // Match the cloned app's composer.json PHP requirement (e.g. ^8.5 needs PHP 8.5 in the image).
        if ($required = $this->composerPhpVersion($d)) {
            if (version_compare($required, (string) $d->runtime_version, '>')) {
                $d->update(['runtime_version' => $required]);
                $this->log($d, 'Matched PHP runtime to composer.json requirement ('.$required.').');
            }
        }

        $this->ensureRuntimeSecrets($d);
        $this->log($d, $d->buildPack->name.' build pack selected (PHP '.$d->runtime_version.', DB '.($d->database_engine ?: 'none').').');
    }

    private function buildImage(ApplicationDeployment $d): void
    {
        if (config('infrastructure.driver') === 'fake') {
            $this->log($d, 'Simulated Docker image build for '.$d->docker_image.':'.$d->docker_tag.'.');

            return;
        }

        $local = storage_path('app/private/Dockerfile-'.$d->uuid);
        if (! is_dir(dirname($local))) {
            mkdir(dirname($local), 0750, true);
        }
        file_put_contents($local, $this->dockerfile($d), LOCK_EX);
        try {
            $remote = $this->buildDirectory($d).'/Dockerfile.platform';
            $this->executor->upload($d->server, $local, $remote);
            $context = $this->buildDirectory($d).($d->root_directory === '/' ? '' : $d->root_directory);
            $image = $d->docker_image.':'.$d->docker_tag;
            $this->log($d, 'Running docker build for '.$image.' (timeout '.$this->timeout('build').'s)…');
            $this->command(
                $d,
                'DOCKER_BUILDKIT=1 docker build --progress=plain -f '.RemoteShell::quote($remote).' -t '.RemoteShell::quote($image).' '.RemoteShell::quote($context),
                'build'
            );
            $this->log($d, 'Docker image '.$image.' built successfully.');
        } finally {
            @unlink($local);
        }
    }

    private function services(ApplicationDeployment $d): void
    {
        $network = $d->slug.'-network';
        $this->command($d, 'docker network inspect '.RemoteShell::quote($network).' >/dev/null 2>&1 || docker network create '.RemoteShell::quote($network));

        // Persist Laravel uploads / local files across image rebuilds and redeploys.
        $this->command(
            $d,
            'docker volume inspect '.RemoteShell::quote($d->slug.'-storage').' >/dev/null 2>&1 || docker volume create '.RemoteShell::quote($d->slug.'-storage')
        );

        if ($d->database_engine) {
            $this->provisionDatabase($d, $network);
        }

        if ($d->enable_redis) {
            $this->provisionRedis($d, $network);
        }
    }

    private function provisionRedis(ApplicationDeployment $d, string $network): void
    {
        $container = $d->slug.'-redis';
        $volume = $d->slug.'-redis';

        $this->command(
            $d,
            'docker volume inspect '.RemoteShell::quote($volume).' >/dev/null 2>&1 || docker volume create '.RemoteShell::quote($volume)
        );

        if ($this->containerIsRunning($d, $container)) {
            $this->log($d, 'Redis sidecar '.$container.' already running; reusing persistent volume '.$volume.'.');

            return;
        }

        $this->command($d, 'docker rm -f '.RemoteShell::quote($container).' >/dev/null 2>&1 || true');
        $this->command(
            $d,
            'docker run -d --name '.RemoteShell::quote($container)
            .' --network '.RemoteShell::quote($network)
            .' --restart unless-stopped'
            .' -v '.RemoteShell::quote($volume.':/data')
            .' redis:7-alpine redis-server --appendonly yes'
        );
        $this->log($d, 'Redis cache and queue backend provisioned with persistent volume '.$volume.'.');
    }

    private function provisionDatabase(ApplicationDeployment $d, string $network): void
    {
        $container = $d->slug.'-db';
        $volume = $d->slug.'-db';
        // Prefer the existing password so redeploy does not mismatch MariaDB/Postgres init credentials.
        $password = $this->envValue($d, 'DB_PASSWORD') ?: Str::password(32);
        $database = $this->envValue($d, 'DB_DATABASE') ?: 'platform';
        $user = $this->envValue($d, 'DB_USERNAME') ?: 'platform';

        $this->upsertEnv($d, 'DB_CONNECTION', $d->database_engine === 'postgresql' ? 'pgsql' : 'mysql', false);
        $this->upsertEnv($d, 'DB_HOST', $container, false);
        $this->upsertEnv($d, 'DB_PORT', $d->database_engine === 'postgresql' ? '5432' : '3306', false);
        $this->upsertEnv($d, 'DB_DATABASE', $database, false);
        $this->upsertEnv($d, 'DB_USERNAME', $user, false);
        $this->upsertEnv($d, 'DB_PASSWORD', $password, true);

        // Never `docker rm -v` — named volumes must survive git update / redeploy.
        $this->command(
            $d,
            'docker volume inspect '.RemoteShell::quote($volume).' >/dev/null 2>&1 || docker volume create '.RemoteShell::quote($volume)
        );

        if ($this->containerIsRunning($d, $container)) {
            $this->log($d, ($d->database_engine === 'postgresql' ? 'PostgreSQL' : 'MariaDB').' sidecar '.$container.' already running; reusing volume '.$volume.'.');
            $this->waitForDatabase($d, $container, $password);
            $this->log($d, 'Database sidecar is accepting connections.');

            return;
        }

        $this->command($d, 'docker rm -f '.RemoteShell::quote($container).' >/dev/null 2>&1 || true');

        if ($d->database_engine === 'postgresql') {
            $image = 'postgres:16-alpine';
            $env = ' -e '.RemoteShell::quote('POSTGRES_DB='.$database)
                .' -e '.RemoteShell::quote('POSTGRES_USER='.$user)
                .' -e '.RemoteShell::quote('POSTGRES_PASSWORD='.$password);
            $mount = ' -v '.RemoteShell::quote($volume.':/var/lib/postgresql/data');
        } else {
            // Match marketplace WordPress path: MariaDB 11 with /var/lib/mysql.
            $image = 'mariadb:11';
            $env = ' -e '.RemoteShell::quote('MYSQL_DATABASE='.$database)
                .' -e '.RemoteShell::quote('MYSQL_USER='.$user)
                .' -e '.RemoteShell::quote('MYSQL_PASSWORD='.$password)
                .' -e '.RemoteShell::quote('MYSQL_ROOT_PASSWORD='.$password);
            $mount = ' -v '.RemoteShell::quote($volume.':/var/lib/mysql');
        }

        $this->command(
            $d,
            'docker run -d --name '.RemoteShell::quote($container)
            .' --network '.RemoteShell::quote($network)
            .' --restart unless-stopped'
            .$env.$mount.' '.RemoteShell::quote($image),
            'pull'
        );
        $this->log($d, ($d->database_engine === 'postgresql' ? 'PostgreSQL' : 'MariaDB').' database sidecar '.$container.' created (volume '.$volume.' preserved).');
        $this->waitForDatabase($d, $container, $password);
        $this->log($d, 'Database sidecar is accepting connections.');
    }

    private function containerIsRunning(ApplicationDeployment $d, string $container): bool
    {
        if (config('infrastructure.driver') === 'fake') {
            return false;
        }

        try {
            $status = trim($this->executor->execute(
                $d->server,
                'docker inspect -f {{.State.Running}} '.RemoteShell::quote($container).' 2>/dev/null || true',
                20
            ));

            return $status === 'true';
        } catch (Throwable) {
            return false;
        }
    }

    private function waitForDatabase(ApplicationDeployment $d, string $container, string $password): void
    {
        if (config('infrastructure.driver') === 'fake') {
            return;
        }

        if ($d->database_engine === 'postgresql') {
            $ping = 'docker exec '.RemoteShell::quote($container)
                .' pg_isready -U '.RemoteShell::quote($this->envValue($d, 'DB_USERNAME') ?: 'platform');
        } else {
            $ping = 'docker exec -e '.RemoteShell::quote('MYSQL_PWD='.$password).' '
                .RemoteShell::quote($container)
                .' sh -lc '.RemoteShell::quote('mariadb-admin ping -h 127.0.0.1 -uroot --silent || mysqladmin ping -h 127.0.0.1 -uroot --silent');
        }

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            try {
                $this->executor->execute($d->server, $ping, 20);

                return;
            } catch (Throwable) {
                if ($attempt === 30) {
                    break;
                }
                sleep(2);
            }
        }

        throw new RuntimeException('Managed database sidecar '.$container.' did not become ready in time.');
    }

    private function migrate(ApplicationDeployment $d): void
    {
        if ($d->framework !== 'laravel') {
            $this->log($d, 'No migrations for '.$d->framework.'.');

            return;
        }

        if (! $d->database_engine) {
            $this->log($d, 'Skipping migrations — no database sidecar configured.');

            return;
        }

        $this->log($d, 'Running php artisan migrate --force…');
        $this->command(
            $d,
            'docker run --rm --network '.RemoteShell::quote($d->slug.'-network')
            .$this->dockerEnvFlags($d).' '
            .RemoteShell::quote($d->docker_image.':'.$d->docker_tag)
            .' php artisan migrate --force',
            'default'
        );
        $this->log($d, 'Migrations completed.');
    }

    private function start(ApplicationDeployment $d): void
    {
        $image = RemoteShell::quote($d->docker_image.':'.$d->docker_tag);
        $network = RemoteShell::quote($d->slug.'-network');
        $env = $this->dockerEnvFlags($d);
        $publish = $d->domain
            ? ''
            : ' -p '.RemoteShell::quote(((int) $d->container_port).':'.((int) $d->container_port));
        $storage = $d->framework === 'laravel'
            ? ' -v '.RemoteShell::quote($d->slug.'-storage:/app/storage/app')
            : '';

        // Recreate app (+ workers) only. DB/Redis sidecars and named volumes stay put.
        $this->command($d, 'docker rm -f '.RemoteShell::quote($d->slug).' >/dev/null 2>&1 || true');
        $this->command(
            $d,
            'docker run -d --name '.RemoteShell::quote($d->slug)
            .' --network '.$network
            .' --restart unless-stopped'
            .$publish.$storage.$env.' '.$image
        );
        // Ensure Vite/asset URLs prefer https when served through Traefik.
        $this->command(
            $d,
            'docker exec '.RemoteShell::quote($d->slug).' php artisan config:clear >/dev/null 2>&1 || true'
        );
        $this->log($d, 'Application container '.$d->slug.' started'.($storage !== '' ? ' with persistent storage volume.' : '.'));

        if ($d->framework === 'laravel' && $d->enable_horizon) {
            $this->command($d, 'docker rm -f '.RemoteShell::quote($d->slug.'-horizon').' >/dev/null 2>&1 || true');
            $this->command(
                $d,
                'docker run -d --name '.RemoteShell::quote($d->slug.'-horizon')
                .' --network '.$network.' --restart unless-stopped'.$storage.' '.$env.' '.$image
                .' php artisan horizon'
            );
            $this->log($d, 'Horizon queue supervisor started on Redis.');
        } elseif ($d->framework === 'laravel' && $d->enable_queue) {
            // Horizon replaces a plain queue:work sidecar when both are selected.
            $this->command($d, 'docker rm -f '.RemoteShell::quote($d->slug.'-queue').' >/dev/null 2>&1 || true');
            $this->command(
                $d,
                'docker run -d --name '.RemoteShell::quote($d->slug.'-queue')
                .' --network '.$network.' --restart unless-stopped'.$storage.' '.$env.' '.$image
                .' php artisan queue:work redis --sleep=3 --tries=3 --timeout=90'
            );
            $this->log($d, 'Queue worker started on Redis.');
        }
        if ($d->framework === 'laravel' && $d->enable_scheduler) {
            $this->command($d, 'docker rm -f '.RemoteShell::quote($d->slug.'-scheduler').' >/dev/null 2>&1 || true');
            $this->command(
                $d,
                'docker run -d --name '.RemoteShell::quote($d->slug.'-scheduler')
                .' --network '.$network.' --restart unless-stopped'.$storage.' '.$env.' '.$image
                .' php artisan schedule:work'
            );
            $this->log($d, 'Scheduler sidecar started.');
        }
        if ($d->framework === 'laravel' && $d->enable_reverb) {
            $this->command($d, 'docker rm -f '.RemoteShell::quote($d->slug.'-reverb').' >/dev/null 2>&1 || true');
            $this->command(
                $d,
                'docker run -d --name '.RemoteShell::quote($d->slug.'-reverb')
                .' --network '.$network.' --restart unless-stopped'.$storage.' '.$env.' '.$image
                .' php artisan reverb:start --host=0.0.0.0 --port=8080'
            );
            $this->log($d, 'Reverb websocket server started.');
        }
    }

    private function complete(ApplicationDeployment $d): void
    {
        $this->deployments->execute($d, 'complete');
        $release = $d->releases()->latest('id')->first();
        $release?->update(['commit' => $d->commit_hash]);
    }

    private function dockerfile(ApplicationDeployment $d): string
    {
        $install = $this->composerInstallCommand($d);
        $php = $this->laravelPhpVersion($d);

        return match ($d->framework) {
            'laravel' => implode("\n", [
                "FROM php:{$php}-cli-alpine AS php-base",
                'ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1',
                'ENV APP_ENV=production APP_DEBUG=false',
                'ENV APP_KEY=base64:'.base64_encode(random_bytes(32)),
                'RUN apk add --no-cache git unzip libzip-dev icu-dev oniguruma-dev linux-headers $PHPIZE_DEPS \\',
                '    && docker-php-ext-install pdo pdo_mysql zip intl mbstring pcntl posix \\',
                '    && pecl install redis && docker-php-ext-enable redis \\',
                '    && apk del $PHPIZE_DEPS linux-headers',
                'COPY --from=composer:2 /usr/bin/composer /usr/bin/composer',
                'WORKDIR /app',
                'COPY . .',
                'RUN '.$install,
                'RUN php artisan package:discover --ansi || true',
                'RUN php artisan storage:link || true',
                'RUN chmod -R ug+rwx storage bootstrap/cache || true',
                '',
                'FROM node:22-alpine AS assets',
                'ENV NODE_OPTIONS=--max-old-space-size=768',
                'WORKDIR /app',
                'COPY --from=php-base /app /app',
                'RUN if [ -f package.json ]; then \\',
                '      if [ -f package-lock.json ]; then npm ci; else npm install; fi; \\',
                '      npm run build; \\',
                '      rm -rf node_modules; \\',
                '    else mkdir -p public/build; fi',
                '',
                'FROM php-base',
                'COPY --from=assets /app/public/build /app/public/build',
                "EXPOSE {$d->container_port}",
                'CMD ["php","artisan","serve","--host=0.0.0.0","--port='.$d->container_port.'"]',
                '',
            ]),
            'react' => implode("\n", [
                "FROM node:{$d->runtime_version}-alpine AS build",
                'WORKDIR /app',
                'COPY . .',
                'RUN '.$d->install_command,
                'RUN '.$d->build_command,
                'FROM nginx:alpine',
                'COPY --from=build /app/'.$d->output_directory.' /usr/share/nginx/html',
                "EXPOSE {$d->container_port}",
                '',
            ]),
            default => implode("\n", [
                "FROM node:{$d->runtime_version}-alpine",
                'WORKDIR /app',
                'COPY . .',
                'RUN '.$d->install_command,
                'RUN '.$d->build_command,
                "EXPOSE {$d->container_port}",
                'CMD ["sh","-lc",'.json_encode((string) $d->start_command).']',
                '',
            ]),
        };
    }

    private function composerInstallCommand(ApplicationDeployment $d): string
    {
        $command = trim((string) $d->install_command) ?: 'composer install --no-dev --optimize-autoloader --no-interaction';

        if (! str_contains($command, '--no-scripts')) {
            $command .= ' --no-scripts';
        }

        return $command;
    }

    private function composerPhpVersion(ApplicationDeployment $d): ?string
    {
        if (config('infrastructure.driver') === 'fake') {
            return null;
        }

        $path = $this->buildDirectory($d);
        if ($d->root_directory && $d->root_directory !== '/') {
            $path .= rtrim($d->root_directory, '/');
        }
        $path .= '/composer.json';

        try {
            $json = $this->executor->execute($d->server, 'cat '.RemoteShell::quote($path), 30);
        } catch (Throwable) {
            return null;
        }

        $data = json_decode($json, true);
        $constraint = $data['require']['php'] ?? null;
        if (! is_string($constraint) || ! preg_match('/(\d+\.\d+)/', $constraint, $match)) {
            return null;
        }

        return $match[1];
    }

    /**
     * Prefer PHP 8.4 for Laravel unless an older supported runtime was chosen.
     * PHP 8.5 is allowed only when explicitly selected and will still build.
     */
    private function laravelPhpVersion(ApplicationDeployment $d): string
    {
        $version = trim((string) $d->runtime_version) ?: '8.4';
        if (! preg_match('/^\d+(\.\d+)?$/', $version)) {
            return '8.4';
        }

        return $version;
    }

    private function ensureRuntimeSecrets(ApplicationDeployment $d): void
    {
        if ($d->framework !== 'laravel') {
            return;
        }

        if (! $this->envValue($d, 'APP_KEY')) {
            $this->upsertEnv($d, 'APP_KEY', 'base64:'.base64_encode(random_bytes(32)), true);
        }
        $this->upsertEnv($d, 'APP_ENV', $this->envValue($d, 'APP_ENV') ?: 'production', false);
        $this->upsertEnv($d, 'APP_DEBUG', $this->envValue($d, 'APP_DEBUG') ?: 'false', false);
        // Behind Traefik the container sees HTTP; without these, @vite()/asset() emit http://
        // URLs on an https:// page and browsers block CSS/JS as mixed content.
        $this->upsertEnv($d, 'TRUSTED_PROXIES', $this->envValue($d, 'TRUSTED_PROXIES') ?: '*', false);
        if ($d->domain) {
            $https = 'https://'.$d->domain;
            $this->upsertEnv($d, 'APP_URL', $https, false);
            $this->upsertEnv($d, 'ASSET_URL', $https, false);
        }
    }

    /** @return array<string, string> */
    private function runtimeEnvironment(ApplicationDeployment $d): array
    {
        $d->loadMissing('environmentVariables');
        $env = [];
        foreach ($d->environmentVariables as $variable) {
            $env[$variable->key] = (string) $variable->value;
        }

        $env['TRUSTED_PROXIES'] = $env['TRUSTED_PROXIES'] ?? '*';

        if ($d->domain) {
            $https = 'https://'.$d->domain;
            $env['APP_URL'] = $https;
            $env['ASSET_URL'] = $https;
        }

        if ($d->database_engine === 'mysql' || $d->database_engine === 'mariadb') {
            $env += [
                'DB_CONNECTION' => $env['DB_CONNECTION'] ?? 'mysql',
                'DB_HOST' => $env['DB_HOST'] ?? $d->slug.'-db',
                'DB_PORT' => $env['DB_PORT'] ?? '3306',
                'DB_DATABASE' => $env['DB_DATABASE'] ?? 'platform',
                'DB_USERNAME' => $env['DB_USERNAME'] ?? 'platform',
                'DB_PASSWORD' => $env['DB_PASSWORD'] ?? 'platform-generated',
            ];
        }
        if ($d->database_engine === 'postgresql') {
            $env += [
                'DB_CONNECTION' => $env['DB_CONNECTION'] ?? 'pgsql',
                'DB_HOST' => $env['DB_HOST'] ?? $d->slug.'-db',
                'DB_PORT' => $env['DB_PORT'] ?? '5432',
                'DB_DATABASE' => $env['DB_DATABASE'] ?? 'platform',
                'DB_USERNAME' => $env['DB_USERNAME'] ?? 'platform',
                'DB_PASSWORD' => $env['DB_PASSWORD'] ?? 'platform-generated',
            ];
        }
        if ($d->enable_redis) {
            $env += [
                'REDIS_CLIENT' => 'phpredis',
                'REDIS_HOST' => $d->slug.'-redis',
                'REDIS_PORT' => '6379',
                'CACHE_STORE' => 'redis',
                'SESSION_DRIVER' => 'redis',
            ];
        }
        if ($d->enable_queue || $d->enable_horizon) {
            $env['QUEUE_CONNECTION'] = 'redis';
        }
        if ($d->enable_reverb) {
            $reverbHost = $d->domain ?: $d->slug.'-reverb';
            $env += [
                'BROADCAST_CONNECTION' => 'reverb',
                'REVERB_APP_ID' => $d->uuid,
                'REVERB_APP_KEY' => Str::lower(Str::substr($d->uuid, 0, 8)),
                'REVERB_APP_SECRET' => $env['REVERB_APP_SECRET'] ?? Str::random(32),
                'REVERB_HOST' => $reverbHost,
                'REVERB_PORT' => '8080',
                'REVERB_SCHEME' => $d->domain ? 'https' : 'http',
                'VITE_REVERB_APP_KEY' => Str::lower(Str::substr($d->uuid, 0, 8)),
                'VITE_REVERB_HOST' => $reverbHost,
                'VITE_REVERB_PORT' => '8080',
                'VITE_REVERB_SCHEME' => $d->domain ? 'https' : 'http',
            ];
        }

        return $env;
    }

    private function dockerEnvFlags(ApplicationDeployment $d): string
    {
        $flags = '';
        foreach ($this->runtimeEnvironment($d) as $key => $value) {
            $flags .= ' -e '.RemoteShell::quote($key.'='.$value);
        }

        return $flags;
    }

    private function envValue(ApplicationDeployment $d, string $key): ?string
    {
        $d->loadMissing('environmentVariables');
        $value = $d->environmentVariables->firstWhere('key', $key)?->value;

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function upsertEnv(ApplicationDeployment $d, string $key, string $value, bool $secret): void
    {
        /** @var DeploymentEnvironmentVariable $variable */
        $variable = $d->environmentVariables()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'secret' => $secret]
        );
        $d->unsetRelation('environmentVariables');
        $d->load('environmentVariables');
        unset($variable);
    }

    private function command(ApplicationDeployment $d, string $command, string $timeoutKey = 'default'): void
    {
        try {
            $this->executor->execute($d->server, $command, $this->timeout($timeoutKey));
        } catch (RemoteCommandException $exception) {
            $this->deployments->log($d, 'error', 'Command failed: '.$exception->redactedCommand());
            if ($exception->detail() !== '') {
                $this->deployments->log($d, 'error', 'Remote output: '.str($exception->detail())->limit(8000));
            }
            if ($timeoutKey === 'build') {
                $tail = $this->commandOutputTail($exception);
                if ($tail !== '') {
                    $this->deployments->log($d, 'error', 'Build output (last lines): '.$tail);
                }
                if ($this->looksLikeOutOfMemory($exception)) {
                    $this->deployments->log(
                        $d,
                        'warning',
                        'The Docker build may have run out of memory. Stop other deploys, use a server with 4GB+ RAM, or deploy one application at a time.'
                    );
                }
            }

            throw $exception;
        }
    }

    private function timeout(string $key): int
    {
        return max(1, (int) config('infrastructure.command_timeouts.'.$key, 180));
    }

    private function log(ApplicationDeployment $d, string $message): void
    {
        $this->deployments->log($d, 'success', $message);
    }

    private function buildDirectory(ApplicationDeployment $d): string
    {
        return PlatformPaths::builds().'/'.$d->uuid;
    }

    private function isRetryableGitCloneFailure(RemoteCommandException $exception): bool
    {
        $detail = strtolower($exception->detail());

        return str_contains($detail, 'rpc failed')
            || str_contains($detail, 'early eof')
            || str_contains($detail, 'unexpected disconnect')
            || str_contains($detail, 'invalid index-pack')
            || str_contains($detail, 'curl 92');
    }

    private function commandOutputTail(RemoteCommandException $exception): string
    {
        $combined = trim($exception->stderr."\n".$exception->stdout);
        if ($combined === '') {
            return '';
        }

        return str(substr($combined, -3000))->limit(3000)->toString();
    }

    private function looksLikeOutOfMemory(RemoteCommandException $exception): bool
    {
        $detail = strtolower($exception->detail());

        return str_contains($detail, 'cannot allocate memory')
            || str_contains($detail, 'out of memory')
            || str_contains($detail, 'signal 9')
            || str_contains($detail, 'killed');
    }
}
