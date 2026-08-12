<?php

namespace Tests\Feature;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Contracts\Networking\DnsResolverInterface;
use App\Exceptions\RemoteCommandException;
use App\Jobs\IssueCertificateJob;
use App\Jobs\VerifyDomainJob;
use App\Models\ApplicationDeployment;
use App\Models\Domain;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Infrastructure\FakeServerExecutor;
use App\Services\Networking\DomainNetworkService;
use App\Services\Networking\FakeDnsResolver;
use App\Services\Networking\SystemDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DomainManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_tenant_scoped_domain_and_queue_verification(): void
    {
        Bus::fake();
        [$user, $tenant, $server, $deployment] = $this->owner();
        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post(route('domains.store'), [
            'application_deployment_id' => $deployment->id,
            'hostname' => 'app.example.com',
            'ssl_enabled' => 1,
            'force_https' => 1,
            'auto_renew' => 1,
        ]);
        $domain = Domain::firstOrFail();
        $response->assertRedirect(route('domains.show', $domain));
        Bus::assertDispatchedSync(VerifyDomainJob::class);
        $this->assertSame($server->ip_address, $domain->expected_value);
        $this->assertSame('app.example.com', $deployment->refresh()->domain);
    }

    public function test_fake_networking_pipeline_verifies_dns_configures_proxy_and_issues_ssl(): void
    {
        [$user, $tenant, $server, $deployment] = $this->owner();
        $domain = $this->domain($tenant, $server, $deployment, $user);
        $service = app(DomainNetworkService::class);
        $this->assertTrue($service->verifyDns($domain));
        $service->configure($domain->refresh());
        $service->issueCertificate($domain->refresh());
        $domain->refresh();
        $this->assertSame('active', $domain->status);
        $this->assertSame('verified', $domain->dns_status);
        $this->assertSame('configured', $domain->proxy_status);
        $this->assertSame('valid', $domain->ssl_status);
        $this->assertTrue($domain->certificate_expires_at->isFuture());
        $this->assertTrue($domain->hasValidSsl());
        $this->assertSame('running', $server->refresh()->proxy_status);
    }

    public function test_dns_mismatch_remains_pending_and_exposes_observed_values(): void
    {
        [$user, $tenant, $server, $deployment] = $this->owner();
        $domain = $this->domain($tenant, $server, $deployment, $user);
        $this->app->instance(DnsResolverInterface::class, new class implements DnsResolverInterface
        {
            public function resolve(string $hostname): array
            {
                return ['198.51.100.99'];
            }
        });
        $this->assertFalse(app(DomainNetworkService::class)->verifyDns($domain));
        $domain->refresh();
        $this->assertSame('mismatch', $domain->dns_status);
        $this->assertSame(['198.51.100.99'], $domain->resolved_values);
        $this->assertSame('pending', $domain->status);
        $this->assertFalse($domain->hasValidSsl());
        $this->assertSame('Unverified', $domain->dnsStatusLabel());
    }

    public function test_dns_mismatch_clears_bogus_ssl_valid_state(): void
    {
        [$user, $tenant, $server, $deployment] = $this->owner();
        $domain = $this->domain($tenant, $server, $deployment, $user);
        $domain->update([
            'dns_status' => 'verified',
            'status' => 'active',
            'proxy_status' => 'configured',
            'ssl_status' => 'valid',
            'certificate_issued_at' => now()->subYears(3),
            'certificate_expires_at' => now()->subYears(3)->addDays(90),
            'certificate_serial' => 'FAKE',
        ]);

        $this->app->instance(DnsResolverInterface::class, new class implements DnsResolverInterface
        {
            public function resolve(string $hostname): array
            {
                return ['198.51.100.99'];
            }
        });

        $this->assertFalse(app(DomainNetworkService::class)->verifyDns($domain->refresh()));
        $domain->refresh();
        $this->assertSame('mismatch', $domain->dns_status);
        $this->assertSame('pending', $domain->ssl_status);
        $this->assertNull($domain->certificate_expires_at);
        $this->assertNull($domain->certificate_issued_at);
        $this->assertFalse($domain->hasValidSsl());
        $this->assertSame('Pending', $domain->sslStatusLabel());
    }

    public function test_ssl_issue_refuses_when_dns_is_not_pointed(): void
    {
        [$user, $tenant, $server, $deployment] = $this->owner();
        $domain = $this->domain($tenant, $server, $deployment, $user);
        $this->app->instance(DnsResolverInterface::class, new class implements DnsResolverInterface
        {
            public function resolve(string $hostname): array
            {
                return ['198.51.100.99'];
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DNS must point to this server');
        app(DomainNetworkService::class)->issueCertificate($domain);
    }

    public function test_ssl_is_not_valid_when_dns_unverified_even_if_dates_exist(): void
    {
        [$user, $tenant, $server, $deployment] = $this->owner();
        $domain = $this->domain($tenant, $server, $deployment, $user);
        $domain->update([
            'dns_status' => 'pending',
            'ssl_status' => 'valid',
            'certificate_issued_at' => now(),
            'certificate_expires_at' => now()->addDays(90),
        ]);

        $this->assertFalse($domain->hasValidSsl());
        $this->assertSame('Pending', $domain->sslStatusLabel());
        $this->assertFalse($domain->connectionApplicationComplete());
        $this->assertFalse($domain->connectionProxyComplete());
    }

    public function test_system_dns_resolver_merges_doh_when_local_lookup_is_stale(): void
    {
        Http::fake([
            'cloudflare-dns.com/*' => Http::response([
                'Answer' => [
                    ['name' => 'aps.recurringpress.com.', 'type' => 1, 'TTL' => 300, 'data' => '142.93.127.29'],
                ],
            ], 200),
        ]);

        $resolved = (new SystemDnsResolver)->resolve('aps.recurringpress.com');

        $this->assertContains('142.93.127.29', $resolved);
    }

    public function test_fake_dns_resolver_uses_real_lookup_for_public_hostnames(): void
    {
        $system = $this->createMock(SystemDnsResolver::class);
        $system->expects($this->once())
            ->method('resolve')
            ->with('bigdemo.recurringpress.com')
            ->willReturn(['164.92.149.245']);

        $resolver = new FakeDnsResolver($system);
        $this->assertSame(['164.92.149.245'], $resolver->resolve('bigdemo.recurringpress.com'));
    }


    public function test_viewer_cannot_add_or_remove_domains(): void
    {
        [$owner, $tenant, $server, $deployment] = $this->owner();
        $viewer = User::factory()->create();
        $tenant->users()->attach($viewer, ['role' => 'viewer']);
        $domain = $this->domain($tenant, $server, $deployment, $owner);
        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])->post(route('domains.store'), [
            'application_deployment_id' => $deployment->id,
            'hostname' => 'blocked.example.com',
        ])->assertForbidden();
        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])->delete(route('domains.destroy', $domain))->assertForbidden();
    }

    public function test_owner_can_delete_domain_when_remote_teardown_fails(): void
    {
        config(['infrastructure.driver' => 'ssh']);
        [$user, $tenant, $server, $deployment] = $this->owner();
        $domain = $this->domain($tenant, $server, $deployment, $user, 'pending.example.com');
        $deployment->update(['domain' => $domain->hostname]);
        $domainId = $domain->id;

        $this->app->instance(ServerExecutorInterface::class, new class extends FakeServerExecutor
        {
            public function execute(Server $server, string $command, ?int $timeoutSeconds = null): string
            {
                throw new RemoteCommandException(
                    $command,
                    1,
                    '',
                    'grep: no matches',
                    false,
                    30,
                    'The remote infrastructure command failed (exit 1).'
                );
            }
        });

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('domains.destroy', $domain))
            ->assertRedirect(route('domains.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('domains', ['id' => $domainId]);
        $this->assertNull($deployment->refresh()->domain);
    }

    public function test_domain_remove_purge_script_ignores_absent_hostname_matches(): void
    {
        config(['infrastructure.driver' => 'ssh']);
        [$user, $tenant, $server, $deployment] = $this->owner();
        $domain = $this->domain($tenant, $server, $deployment, $user, 'stale.example.com');

        $executor = new class extends FakeServerExecutor
        {
            /** @var list<string> */
            public array $commands = [];

            public function execute(Server $server, string $command, ?int $timeoutSeconds = null): string
            {
                $this->commands[] = $command;

                return parent::execute($server, $command, $timeoutSeconds);
            }
        };
        $this->app->instance(ServerExecutorInterface::class, $executor);

        app(DomainNetworkService::class)->remove($domain);

        $purge = collect($executor->commands)->first(fn (string $command) => str_contains($command, 'grep -Fq'));
        $this->assertNotNull($purge);
        $this->assertStringContainsString('done; true', $purge);
        $this->assertStringContainsString('Host(`stale.example.com`)', $purge);
    }

    public function test_domains_index_loads_when_server_is_soft_deleted(): void
    {
        [$user, $tenant, $server, $deployment] = $this->owner();
        $domain = $this->domain($tenant, $server, $deployment, $user, 'orphaned.example.com');
        $serverName = $server->name;
        $server->delete();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('domains.index'))
            ->assertOk()
            ->assertSee('orphaned.example.com')
            ->assertSee($serverName)
            ->assertDontSee('Server removed');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('domains.show', $domain))
            ->assertOk()
            ->assertSee('orphaned.example.com')
            ->assertSee($serverName);
    }

    public function test_renewal_command_only_queues_managed_expiring_certificates(): void
    {
        Bus::fake();
        [$user, $tenant, $server, $deployment] = $this->owner();
        $expiring = $this->domain($tenant, $server, $deployment, $user);
        $expiring->update(['ssl_status' => 'expiring', 'certificate_expires_at' => now()->addDays(10), 'auto_renew' => true]);
        $safe = $this->domain($tenant, $server, $deployment, $user, 'safe.example.com');
        $safe->update(['ssl_status' => 'valid', 'certificate_expires_at' => now()->addDays(70), 'auto_renew' => true]);
        $this->artisan('domains:renew-certificates')->assertSuccessful();
        Bus::assertDispatched(IssueCertificateJob::class, fn ($job) => $job->domainId === $expiring->id);
        Bus::assertNotDispatched(IssueCertificateJob::class, fn ($job) => $job->domainId === $safe->id);
    }

    private function owner(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => fake()->unique()->company()]);
        $tenant->users()->attach($user, ['role' => 'owner']);
        $server = Server::create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'provider' => 'custom',
            'ip_address' => '203.0.113.42',
            'operating_system' => 'ubuntu-24.04',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
            'cpu_cores' => 4,
            'memory_mb' => 8192,
            'disk_gb' => 160,
        ]);
        $deployment = ApplicationDeployment::create([
            'tenant_id' => $tenant->id,
            'server_id' => $server->id,
            'created_by' => $user->id,
            'name' => 'Portal',
            'deployment_type' => 'custom',
            'docker_image' => 'example/portal',
            'docker_tag' => 'latest',
            'container_port' => 3000,
            'restart_policy' => 'unless-stopped',
            'status' => 'running',
        ]);

        return [$user, $tenant, $server, $deployment];
    }

    private function domain(Tenant $tenant, Server $server, ApplicationDeployment $deployment, User $user, string $hostname = 'app.example.com'): Domain
    {
        return Domain::create([
            'tenant_id' => $tenant->id,
            'application_deployment_id' => $deployment->id,
            'server_id' => $server->id,
            'created_by' => $user->id,
            'hostname' => $hostname,
            'expected_value' => $server->ip_address,
            'ssl_enabled' => true,
            'force_https' => true,
            'auto_renew' => true,
        ]);
    }
}
