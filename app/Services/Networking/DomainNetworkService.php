<?php

namespace App\Services\Networking;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Contracts\Networking\DnsResolverInterface;
use App\Events\DomainStatusChanged;
use App\Exceptions\RemoteCommandException;
use App\Models\Domain;
use App\Models\Server;
use App\Support\RemoteShell;
use RuntimeException;
use Throwable;

class DomainNetworkService
{
    public function __construct(
        private readonly DnsResolverInterface $dns,
        private readonly ServerExecutorInterface $executor,
        private readonly AcmeEmailResolver $acmeEmails,
    ) {}

    public function verifyDns(Domain $domain): bool
    {
        $resolved = $this->dns->resolve($domain->hostname);
        $expected = strtolower(trim((string) $domain->expected_value));
        $normalized = array_map(fn (string $value) => strtolower(trim($value)), $resolved);
        $verified = $expected !== '' && in_array($expected, $normalized, true);

        $updates = [
            'dns_status' => $verified ? 'verified' : ($resolved === [] ? 'pending' : 'mismatch'),
            'status' => $verified ? 'verifying' : 'pending',
            'resolved_values' => $resolved,
            'last_dns_check_at' => now(),
            'dns_verified_at' => $verified ? now() : null,
            'failure_reason' => $verified
                ? null
                : ($resolved === []
                    ? 'No public DNS record found for this hostname yet.'
                    : 'DNS does not point to the expected server yet. Observed: '.implode(', ', $resolved)),
        ];

        if (! $verified) {
            $updates = array_merge($updates, $this->clearUntrustedSslState($domain));
            // Proxy route may still exist on the server, but the domain is not live yet.
            if ($domain->status === 'active') {
                $updates['status'] = 'pending';
            }
        } else {
            $updates = array_merge($updates, $this->reconcileSslStatus($domain));
            if (($updates['ssl_status'] ?? $domain->ssl_status) === 'valid'
                || (! $domain->ssl_enabled && ($domain->proxy_status === 'configured'))) {
                $updates['status'] = 'active';
                $updates['failure_reason'] = null;
            }
        }

        $domain->update($updates);
        $this->broadcast($domain->refresh());

        return $verified;
    }

    public function configure(Domain $domain): void
    {
        if ($domain->dns_status !== 'verified') {
            throw new RuntimeException('DNS must be verified before configuring the proxy.');
        }
        $this->ensureProxy($domain);
        $this->applyRoute($domain);
        $this->trustHostnameOnApplication($domain);
        $domain->update([
            'proxy_status' => 'configured',
            'proxy_configured_at' => now(),
            'status' => $domain->ssl_enabled ? 'verifying' : 'active',
            'ssl_status' => $domain->ssl_enabled
                ? (in_array($domain->ssl_status, ['valid', 'expiring'], true) ? $domain->ssl_status : 'pending')
                : 'disabled',
            'failure_reason' => null,
        ]);
        $this->broadcast($domain->refresh());
    }

    public function issueCertificate(Domain $domain): void
    {
        if (! $domain->ssl_enabled) {
            $domain->update(['ssl_status' => 'disabled', 'status' => 'active']);

            return;
        }

        if (! $this->verifyDns($domain->refresh())) {
            throw new RuntimeException('DNS must point to this server before a certificate can be issued.');
        }

        $domain->refresh();

        if ($domain->proxy_status !== 'configured') {
            $this->configure($domain);
            $domain->refresh();
        }

        if (config('infrastructure.driver') === 'fake') {
            $domain->update([
                'ssl_status' => 'valid',
                'status' => 'active',
                'certificate_serial' => strtoupper(substr(hash('sha256', $domain->hostname.now()), 0, 24)),
                'certificate_issued_at' => now(),
                'certificate_expires_at' => now()->addDays(90),
                'last_renewal_at' => now(),
                'failure_reason' => null,
            ]);
            $this->broadcast($domain->refresh());

            return;
        }

        // ACME needs a moment after the router appears, so poll before giving up.
        $certificate = null;
        for ($attempt = 1; $attempt <= 8 && ! $certificate; $attempt++) {
            if ($attempt > 1) {
                sleep(5);
            }
            $certificate = $this->inspectCertificate($domain->hostname);
        }
        if (! $certificate) {
            throw new RuntimeException($this->certificateFailureReason($domain));
        }

        $expires = now()->setTimestamp($certificate['validTo_time_t']);
        if ($expires->isPast()) {
            $domain->update([
                'ssl_status' => 'expired',
                'status' => 'failed',
                'certificate_serial' => $certificate['serialNumberHex'] ?? null,
                'certificate_issued_at' => isset($certificate['validFrom_time_t'])
                    ? now()->setTimestamp($certificate['validFrom_time_t'])
                    : null,
                'certificate_expires_at' => $expires,
                'failure_reason' => 'TLS certificate for '.$domain->hostname.' is expired.',
            ]);
            $this->broadcast($domain->refresh());
            throw new RuntimeException('TLS certificate for '.$domain->hostname.' is expired.');
        }

        $daysRemaining = (int) now()->diffInDays($expires, false);
        $domain->update([
            'ssl_status' => $daysRemaining <= (int) config('networking.renew_before_days') ? 'expiring' : 'valid',
            'status' => 'active',
            'certificate_serial' => $certificate['serialNumberHex'] ?? null,
            'certificate_issued_at' => isset($certificate['validFrom_time_t'])
                ? now()->setTimestamp($certificate['validFrom_time_t'])
                : now(),
            'certificate_expires_at' => $expires,
            'last_renewal_at' => now(),
            'failure_reason' => null,
        ]);
        // Re-write the route so force-HTTPS redirect is applied only after a real cert exists.
        if ($domain->force_https && ! $domain->redirect_to) {
            try {
                $this->applyRoute($domain->refresh());
            } catch (Throwable) {
                // Certificate is already valid; redirect middleware can wait for the next configure.
            }
        }
        $this->broadcast($domain->refresh());
    }

    public function remove(Domain $domain): void
    {
        if (config('infrastructure.driver') === 'fake') {
            return;
        }

        // Best-effort: missing Traefik files / already-absent routes must not block DB delete.
        try {
            $this->executor->execute($domain->server, $this->sudo(
                $domain->server,
                'rm -f '.RemoteShell::quote($this->remoteRoutePath($domain))
            ));
            $this->purgeStaleHostnameRoutes($domain);
        } catch (RemoteCommandException $exception) {
            report($exception);
        }
    }

    /**
     * Drop SSL Valid / dates that were claimed without a live DNS match.
     *
     * @return array<string, mixed>
     */
    private function clearUntrustedSslState(Domain $domain): array
    {
        if (! $domain->ssl_enabled) {
            return ['ssl_status' => 'disabled'];
        }

        if (! in_array($domain->ssl_status, ['valid', 'expiring', 'expired'], true)
            && $domain->certificate_expires_at === null) {
            return ['ssl_status' => 'pending'];
        }

        return [
            'ssl_status' => 'pending',
            'certificate_serial' => null,
            'certificate_issued_at' => null,
            'certificate_expires_at' => null,
            'last_renewal_at' => null,
        ];
    }

    /**
     * Keep ssl_status honest against certificate expiry while DNS remains verified.
     *
     * @return array<string, mixed>
     */
    private function reconcileSslStatus(Domain $domain): array
    {
        if (! $domain->ssl_enabled) {
            return ['ssl_status' => 'disabled'];
        }

        if (! $domain->certificate_expires_at) {
            return in_array($domain->ssl_status, ['valid', 'expiring'], true)
                ? ['ssl_status' => 'pending', 'certificate_serial' => null, 'certificate_issued_at' => null]
                : [];
        }

        if ($domain->certificate_expires_at->isPast()) {
            return ['ssl_status' => 'expired', 'status' => 'failed', 'failure_reason' => 'TLS certificate has expired.'];
        }

        $days = (int) now()->diffInDays($domain->certificate_expires_at, false);
        if ($days <= (int) config('networking.renew_before_days')) {
            return ['ssl_status' => 'expiring'];
        }

        return in_array($domain->ssl_status, ['valid', 'expiring'], true) ? ['ssl_status' => 'valid'] : [];
    }

    /**
     * Ask the proxy why ACME did not deliver a certificate so the deployment log
     * shows the real reason (rate limit, DNS, rejected contact address, …).
     */
    private function certificateFailureReason(Domain $domain): string
    {
        $fallback = 'Certificate issuance is still pending at the proxy for '.$domain->hostname.'.';

        try {
            $log = trim($this->executor->execute(
                $domain->server,
                'docker logs --since 10m '.RemoteShell::quote(config('networking.proxy_name')).' 2>&1 | grep -i acme | tail -n 3',
                60
            ));
        } catch (Throwable) {
            return $fallback;
        }

        if ($log === '') {
            return $fallback;
        }

        $log = trim(preg_replace(['/\e\[[0-9;]*m/', '/\s*\R\s*/'], ['', ' | '], $log) ?? $log);

        return $fallback.' Proxy said: '.str($log)->limit(500);
    }

    /**
     * Let's Encrypt rejects placeholder contact addresses, and the address is
     * baked into the proxy container, so validate before it is ever started.
     */
    private function acmeEmail(): string
    {
        return $this->acmeEmails->resolve();
    }

    private function ensureProxy(Domain $domain): void
    {
        $server = $domain->server;
        if (config('infrastructure.driver') !== 'fake') {
            $this->acmeEmail();
            $network = config('networking.proxy_network');
            $name = config('networking.proxy_name');
            $dynamic = rtrim((string) config('networking.proxy_dynamic_path'), '/');
            $certificates = config('networking.proxy_certificates_volume');

            $this->executor->execute($server, $this->sudo(
                $server,
                'docker network inspect '.RemoteShell::quote($network).' >/dev/null 2>&1 || docker network create '.RemoteShell::quote($network)
            ));
            $this->executor->execute($server, $this->sudo(
                $server,
                'install -d -m 0750 '.RemoteShell::quote($dynamic).' && (docker volume inspect '.RemoteShell::quote($certificates).' >/dev/null 2>&1 || docker volume create '.RemoteShell::quote($certificates).')'
            ));

            // A proxy installed during provisioning has no file provider, so adopt it
            // by recreating it with the routing and ACME flags this service depends on.
            $run = $this->proxyRunCommand($name, $network, $dynamic, $certificates);
            $this->executor->execute(
                $server,
                $this->sudo($server, 'if docker inspect --format '.RemoteShell::quote('{{json .Args}}').' '.RemoteShell::quote($name).' 2>/dev/null | grep -q providers.file.directory && docker inspect --format '.RemoteShell::quote('{{json .Args}}').' '.RemoteShell::quote($name).' 2>/dev/null | grep -qF '.RemoteShell::quote('acme.email='.$this->acmeEmail()).'; then :; else docker rm -f '.RemoteShell::quote($name).' >/dev/null 2>&1 || true; '.$run.'; fi'),
                180
            );
        }
        $server->update(['proxy_status' => 'running', 'proxy_version' => config('networking.proxy_image'), 'proxy_network' => config('networking.proxy_network'), 'proxy_installed_at' => $server->proxy_installed_at ?? now()]);
    }

    private function proxyRunCommand(string $name, string $network, string $dynamic, string $certificates): string
    {
        return 'docker run -d --name '.RemoteShell::quote($name)
            .' --restart unless-stopped --network '.RemoteShell::quote($network)
            .' -p 80:80 -p 443:443'
            .' -v '.RemoteShell::quote('/var/run/docker.sock:/var/run/docker.sock:ro')
            .' -v '.RemoteShell::quote($dynamic.':/etc/traefik/dynamic:ro')
            .' -v '.RemoteShell::quote($certificates.':/letsencrypt').' '
            .RemoteShell::quote(config('networking.proxy_image'))
            .' --providers.docker=true --providers.docker.exposedbydefault=false'
            .' --providers.file.directory=/etc/traefik/dynamic --providers.file.watch=true'
            .' --entrypoints.web.address=:80 --entrypoints.websecure.address=:443'
            .' --certificatesresolvers.letsencrypt.acme.httpchallenge=true'
            .' --certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web'
            .' --certificatesresolvers.letsencrypt.acme.email='.RemoteShell::quote($this->acmeEmail())
            .' --certificatesresolvers.letsencrypt.acme.storage=/letsencrypt/acme.json';
    }

    /**
     * Apps like Nextcloud / Laravel reject unknown hosts or need APP_URL updated.
     */
    private function trustHostnameOnApplication(Domain $domain): void
    {
        if (config('infrastructure.driver') === 'fake') {
            return;
        }

        $domain->loadMissing('deployment.application', 'deployment.environmentVariables');
        $slug = $domain->deployment?->slug;
        if (! $slug) {
            return;
        }

        $deployment = $domain->deployment;
        $image = strtolower((string) ($deployment?->docker_image ?? ''));
        $appSlug = strtolower((string) ($deployment?->application?->slug ?? ''));
        $framework = strtolower((string) ($deployment?->framework ?? ''));
        $isNextcloud = str_contains($image, 'nextcloud') || $appSlug === 'nextcloud';
        $isLaravel = $framework === 'laravel' || in_array($deployment?->deployment_type, ['git', 'web'], true);

        try {
            if ($isNextcloud) {
                $this->executor->execute(
                    $domain->server,
                    'docker exec '.RemoteShell::quote($slug).' php occ config:system:set trusted_domains 0 --value='.RemoteShell::quote($domain->hostname)
                    .' >/dev/null 2>&1 || true; '
                    .'docker exec '.RemoteShell::quote($slug).' php occ config:system:set overwrite.cli.url --value='.RemoteShell::quote('https://'.$domain->hostname)
                    .' >/dev/null 2>&1 || true; '
                    .'docker exec '.RemoteShell::quote($slug).' php occ config:system:set overwriteprotocol --value=https >/dev/null 2>&1 || true',
                    60
                );
            }

            if ($isLaravel && $deployment) {
                $appUrl = 'https://'.$domain->hostname;
                foreach (['APP_URL' => $appUrl, 'ASSET_URL' => $appUrl, 'TRUSTED_PROXIES' => '*'] as $key => $value) {
                    $variable = $deployment->environmentVariables->firstWhere('key', $key);
                    if ($variable) {
                        $variable->update(['value' => $value]);
                    } else {
                        $deployment->environmentVariables()->create([
                            'key' => $key,
                            'value' => $value,
                            'secret' => false,
                        ]);
                    }
                }
                $deployment->unsetRelation('environmentVariables');

                // Best-effort live update for containers that read .env on boot.
                $this->executor->execute(
                    $domain->server,
                    'docker exec '.RemoteShell::quote($slug).' sh -lc '
                    .RemoteShell::quote(
                        'if [ -f /app/.env ]; then '
                        .'for pair in APP_URL='.$appUrl.' ASSET_URL='.$appUrl.' TRUSTED_PROXIES=*; do '
                        .'key=${pair%%=*}; val=${pair#*=}; '
                        .'grep -q "^${key}=" /app/.env && sed -i "s|^${key}=.*|${key}=${val}|" /app/.env || echo "${key}=${val}" >> /app/.env; '
                        .'done; fi; php artisan config:clear >/dev/null 2>&1 || true'
                    )
                    .' >/dev/null 2>&1 || true; docker restart '.RemoteShell::quote($slug).' >/dev/null 2>&1 || true',
                    90
                );
            }
        } catch (Throwable) {
            // Proxy routing still succeeds even if app-specific trust commands fail.
        }
    }

    private function applyRoute(Domain $domain): void
    {
        if (config('infrastructure.driver') === 'fake') {
            return;
        }
        $domain->loadMissing('deployment');
        $slug = $domain->deployment?->slug;
        if (! $slug) {
            throw new RuntimeException('Domain has no application container to route to.');
        }

        // Drop leftover Host() files from replaced deployments so Traefik
        // cannot keep sending this hostname to a stale container.
        $this->purgeStaleHostnameRoutes($domain);

        $local = storage_path('app/private/route-'.$domain->uuid.'.yml');
        if (! is_dir(dirname($local))) {
            mkdir(dirname($local), 0750, true);
        }
        file_put_contents($local, $this->routeConfiguration($domain), LOCK_EX);
        try {
            $temporary = '/tmp/uplary-route-'.$domain->uuid.'.yml';
            $destination = $this->remoteRoutePath($domain);
            $this->executor->upload($domain->server, $local, $temporary);
            $this->executor->execute(
                $domain->server,
                $this->sudo(
                    $domain->server,
                    'install -m 0640 '.RemoteShell::quote($temporary).' '.RemoteShell::quote($destination).' && rm -f '.RemoteShell::quote($temporary)
                )
            );
            $network = config('networking.proxy_network');
            $this->executor->execute(
                $domain->server,
                'docker network connect '.RemoteShell::quote($network).' '.RemoteShell::quote($slug).' >/dev/null 2>&1 || true'
            );
        } finally {
            @unlink($local);
        }
    }

    /**
     * Remove other dynamic route files that still claim this hostname.
     */
    private function purgeStaleHostnameRoutes(Domain $domain): void
    {
        $dynamic = rtrim((string) config('networking.proxy_dynamic_path'), '/');
        $keep = $this->remoteRoutePath($domain);
        $needle = 'Host(`'.$domain->hostname.'`)';

        // grep exits 1 when the hostname is absent from a file; that is not a failure for purge.
        $this->executor->execute(
            $domain->server,
            $this->sudo($domain->server, 'for f in '.RemoteShell::quote($dynamic).'/*.yml; do '
            .'[ -f "$f" ] || continue; '
            .'[ "$f" = '.RemoteShell::quote($keep).' ] && continue; '
            .'grep -Fq '.RemoteShell::quote($needle).' "$f" && rm -f "$f"; '
            .'done; true')
        );
    }

    private function sudo(Server $server, string $command): string
    {
        return strcasecmp((string) $server->ssh_username, 'root') === 0
            ? $command
            : 'sudo -n sh -c '.RemoteShell::quote($command);
    }

    private function routeConfiguration(Domain $domain): string
    {
        $key = 'domain-'.str_replace('-', '', $domain->uuid);
        $redirect = $domain->redirect_to;
        $target = $redirect ? 'https://'.$redirect : null;
        $serviceUrl = 'http://'.$domain->deployment->slug.':'.$domain->deployment->container_port;
        // Delay force-HTTPS redirect until a trusted cert exists so ACME HTTP-01 is not redirected away.
        $forceHttps = $domain->force_https && in_array($domain->ssl_status, ['valid', 'expiring'], true);
        $middleware = $redirect ? "{$key}-redirect" : ($forceHttps ? "{$key}-https" : null);
        $middlewareLine = $middleware ? "      middlewares: [{$middleware}]\n" : '';
        $middlewareSection = $redirect
            ? "  middlewares:\n    {$key}-redirect:\n      redirectRegex:\n        regex: \"^https?://.*\"\n        replacement: \"{$target}\"\n        permanent: true\n"
            : ($forceHttps ? "  middlewares:\n    {$key}-https:\n      redirectScheme:\n        scheme: https\n        permanent: true\n" : '');

        return "http:\n  routers:\n    {$key}-web:\n      rule: \"Host(`{$domain->hostname}`)\"\n      entryPoints: [web]\n{$middlewareLine}      service: {$key}\n    {$key}-secure:\n      rule: \"Host(`{$domain->hostname}`)\"\n      entryPoints: [websecure]\n      service: {$key}\n      tls:\n        certResolver: letsencrypt\n{$middlewareSection}  services:\n    {$key}:\n      loadBalancer:\n        servers:\n          - url: \"{$serviceUrl}\"\n";
    }

    private function inspectCertificate(string $hostname): ?array
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $hostname]]);
        $client = @stream_socket_client('ssl://'.$hostname.':443', $errorCode, $errorMessage, 8, STREAM_CLIENT_CONNECT, $context);
        if (! $client) {
            return null;
        }
        $params = stream_context_get_params($client);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! $certificate) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate) ?: null;
        if (! $parsed) {
            return null;
        }

        $subject = strtolower((string) ($parsed['subject']['CN'] ?? ''));
        $issuer = strtolower((string) ($parsed['issuer']['CN'] ?? ''));
        // Never treat Traefik's ephemeral default certificate as Valid.
        if ($subject === 'traefik default cert' || $issuer === 'traefik default cert') {
            return null;
        }

        return $parsed;
    }

    private function remoteRoutePath(Domain $domain): string
    {
        return rtrim((string) config('networking.proxy_dynamic_path'), '/').'/domain-'.$domain->uuid.'.yml';
    }

    private function broadcast(Domain $domain): void
    {
        event(new DomainStatusChanged($domain->tenant_id, $domain->uuid, $domain->status, $domain->dns_status, $domain->ssl_status));
    }
}
