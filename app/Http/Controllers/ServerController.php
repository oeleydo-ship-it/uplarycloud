<?php

namespace App\Http\Controllers;

use App\Enums\ServerProvider;
use App\Enums\ServerStatus;
use App\Http\Requests\StoreServerRequest;
use App\Jobs\CollectOperationsMetricsJob;
use App\Jobs\ProvisionServerJob;
use App\Models\ActivityLog;
use App\Models\DockerContainer;
use App\Models\DockerVolume;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\Billing\PlanLimitService;
use App\Services\Billing\PaidSubscriptionService;
use App\Services\Infrastructure\ManagedInfrastructureService;
use App\Services\Servers\ControlPlaneKeyService;
use App\Services\Servers\ServerInventoryCleanupService;
use App\Services\Servers\ServerProvisionVerifier;
use App\Support\PlatformSettings;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ServerController extends Controller
{
    public function index(Request $request, TenantContext $context, PlatformSettings $settings): View
    {
        $query = Server::query()->where('tenant_id', $context->id())->withCount(['provisioningSteps', 'containers', 'applicationDeployments'])->with(['metrics' => fn ($q) => $q->latest('recorded_at')->limit(1)]);
        $query->when($request->string('search')->isNotEmpty(), fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', '%'.$request->string('search').'%')->orWhere('ip_address', 'like', '%'.$request->string('search').'%')));
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $query->when($request->filled('tag'), fn ($q) => $q->whereJsonContains('tags', $request->string('tag')->toString()));
        match ($request->string('sort')->toString()) {
            'oldest' => $query->oldest(), 'name' => $query->orderBy('name'), default => $query->latest(),
        };
        $tenant = $context->current();
        $tenantServers = Server::where('tenant_id', $context->id());
        $containersQuery = DockerContainer::query()->where('tenant_id', $tenant->id);
        $volumesQuery = DockerVolume::query()->where('tenant_id', $tenant->id);
        $lastBackup = $tenant->backups()->latest('completed_at')->first();

        return view('servers.index', [
            'servers' => $query->paginate(10)->withQueryString(),
            'managedServersEnabled' => $settings->managedServersEnabled() && app(PlanLimitService::class)->allowsFeature($tenant, 'managed_servers'),
            'managedServersPaid' => app(PaidSubscriptionService::class)->allows($tenant),
            'counts' => [
                'all' => $tenantServers->count(),
                'online' => (clone $tenantServers)->where('status', 'online')->count(),
                'offline' => (clone $tenantServers)->where('status', 'offline')->count(),
                'provisioning' => (clone $tenantServers)->where('status', 'provisioning')->count(),
                'containers' => (clone $containersQuery)->count(),
                'containers_running' => (clone $containersQuery)->where('status', 'running')->count(),
                'volumes' => (clone $volumesQuery)->count(),
                'volumes_gb' => round((clone $volumesQuery)->sum('size_bytes') / 1073741824),
                'backups' => $tenant->backups()->count(),
                'last_backup' => $lastBackup?->completed_at?->diffForHumans(short: true),
            ],
            'tags' => $tenantServers->get()->flatMap(fn ($server) => $server->tags ?? [])->unique()->sort()->values(),
        ]);
    }

    public function create(TenantContext $context, ControlPlaneKeyService $keys, PlatformSettings $settings): View
    {
        $this->authorize('create', Server::class);

        $platformPublicKey = $keys->publicKeyForTenant($context->current());

        return view('servers.create', [
            'providers' => ServerProvider::cases(),
            'platformPublicKey' => $platformPublicKey,
            'platformAuthorizeCommand' => $keys->authorizeCommand($platformPublicKey),
            'managedServersEnabled' => $settings->managedServersEnabled() && app(PlanLimitService::class)->allowsFeature($context->current(), 'managed_servers'),
            'cloudConnections' => $context->current()->providerConnections()
                ->where('platform_managed', false)
                ->whereIn('provider', ['digitalocean', 'hetzner'])
                ->where('active', true)
                ->whereNotNull('last_verified_at')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function createManaged(TenantContext $context, PlatformSettings $settings): View
    {
        $this->authorize('create', Server::class);
        abort_unless($settings->managedServersEnabled(), 404);
        app(PlanLimitService::class)->enforceFeature($context->current(), 'managed_servers');

        $connections = ProviderConnection::query()
            ->where('platform_managed', true)
            ->whereIn('provider', ['digitalocean', 'hetzner'])
            ->where('active', true)
            ->whereNotNull('last_verified_at')
            ->orderBy('name')
            ->get();

        return view('servers.create-managed', [
            'cloudConnections' => $connections,
            'cloudPlans' => ManagedServerPlan::query()
                ->whereIn('provider', $connections->pluck('provider')->unique()->all() ?: ['digitalocean', 'hetzner'])
                ->where('active', true)
                ->orderBy('position')
                ->get(),
        ]);
    }

    public function store(StoreServerRequest $request, TenantContext $context, PlanLimitService $limits, ControlPlaneKeyService $keys): RedirectResponse
    {
        $limits->enforce($context->current(), 'servers');
        $server = DB::transaction(function () use ($request, $context, $keys): Server {
            $data = $request->safe()->except([
                'private_key', 'password', 'passphrase', 'tags',
                'install_docker', 'install_proxy', 'install_monitoring', 'authorization_method',
            ]);
            $data['tenant_id'] = $context->id();
            $data['install_docker'] = $request->boolean('install_docker');
            $data['install_proxy'] = $request->boolean('install_proxy');
            $data['install_monitoring'] = $request->boolean('install_monitoring');
            $data['tags'] = collect(explode(',', (string) $request->input('tags')))->map(fn (string $tag) => trim($tag))->filter()->values()->all();
            $server = Server::create($data);

            if ($request->input('authorization_method') === 'platform_key') {
                $server->credential()->create([
                    'private_key' => $keys->privateKeyForTenant($context->current()),
                    'password' => null,
                    'passphrase' => null,
                ]);
            } else {
                $server->credential()->create($request->safe()->only(['private_key', 'password', 'passphrase']));
            }

            foreach (['connect' => 'Connecting', 'system' => 'Checking system', 'docker' => 'Installing Docker', 'configure' => 'Configuring Docker', 'proxy' => 'Installing reverse proxy', 'monitoring' => 'Configuring monitoring', 'verify' => 'Final verification'] as $key => $label) {
                $server->provisioningSteps()->create(['key' => $key, 'label' => $label, 'position' => $server->provisioningSteps()->count() + 1]);
            }
            ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => 'server.created', 'description' => $server->name.' added', 'subject_type' => Server::class, 'subject_id' => $server->id, 'ip_address' => $request->ip()]);

            return $server;
        });
        ProvisionServerJob::dispatch($server);

        return redirect()->route('servers.provisioning', $server);
    }

    public function show(Server $server, TenantContext $context): View|RedirectResponse
    {
        $server = $this->tenantServer($server, $context);
        $this->authorize('view', $server);
        if ($server->isProvisioningIncomplete()) {
            return redirect()->route('servers.provisioning', $server);
        }

        return $this->details($server, $context, app(ServerProvisionVerifier::class));
    }

    public function details(Server $server, TenantContext $context, ServerProvisionVerifier $verifier): View|RedirectResponse
    {
        $server = $this->tenantServer($server, $context);
        $this->authorize('view', $server);

        if ($server->isProvisioningIncomplete()) {
            return redirect()->route('servers.provisioning', $server);
        }

        if ($server->status === ServerStatus::Online && config('infrastructure.driver') === 'ssh') {
            $verificationFailures = $verifier->failures($server);
            if ($verificationFailures !== []) {
                return redirect()
                    ->route('servers.provisioning', $server)
                    ->with('error', implode(' ', $verificationFailures));
            }
        }

        $server->load(['provisioningSteps', 'metrics' => fn ($q) => $q->latest('recorded_at')->limit(24)]);

        $metrics = $server->metrics->sortBy('recorded_at')->values();
        $latestMetric = $metrics->last();

        return view('servers.show', [
            'server' => $server,
            'latestMetric' => $latestMetric,
            'metricHistory' => $metrics,
            'uptimeLabel' => $this->uptimeLabel($server),
            'platformServices' => $this->platformServices($server, $latestMetric),
            'chart' => $this->resourceChart($metrics),
        ]);
    }

    public function refresh(Server $server, TenantContext $context): RedirectResponse
    {
        $server = $this->tenantServer($server, $context);
        $this->authorize('operate', $server);

        if ($server->status === ServerStatus::Online) {
            CollectOperationsMetricsJob::dispatch($server->id);

            $queue = config('infrastructure.queues.monitoring', 'monitoring');

            return redirect()
                ->route('servers.details', $server)
                ->with('success', 'Metric collection queued for '.$server->name.'. Docker/status refreshes when the '.$queue.' worker runs (php artisan queue:work --queue='.$queue.',default).')
                ->with('metrics_refresh', true);
        }

        return redirect()
            ->route('servers.details', $server)
            ->with('error', 'Metrics can only be collected while the server is online.');
    }

    public function destroy(Request $request, Server $server, TenantContext $context, ServerInventoryCleanupService $cleanup, ManagedInfrastructureService $infrastructure): RedirectResponse
    {
        $server = $this->tenantServer($server, $context);
        $this->authorize('delete', $server);

        if ($server->applicationDeployments()->exists()) {
            return redirect()
                ->route('servers.index')
                ->with('error', 'Remove attached applications before destroying this server. Open Applications → Your applications to delete them first.');
        }

        $name = $server->name;
        $ipAddress = $server->ip_address;
        $destroysRemoteResources = $server->isByoCloud();
        $remoteAlreadyDeleted = $destroysRemoteResources && $request->boolean('remote_already_deleted');
        $forceDestroy = $destroysRemoteResources && $request->boolean('force_destroy');
        $providerResult = null;

        if ($destroysRemoteResources) {
            if ($remoteAlreadyDeleted) {
                $request->validate([
                    'remote_already_deleted' => ['required', 'accepted'],
                    'confirmation' => ['required', 'string', Rule::in([$name])],
                ], [
                    'confirmation.in' => 'Type the server name exactly to remove its stale local record.',
                ]);
                $providerResult = ['status' => 'already_deleted_at_provider'];
            } else {
                $destroyRules = $forceDestroy
                    ? ['force_destroy' => ['required', 'accepted']]
                    : ['destroy_remote' => ['required', 'accepted']];

                $request->validate([
                    ...$destroyRules,
                    'confirmation' => ['required', 'string', Rule::in([$ipAddress])],
                ], [
                    'confirmation.in' => 'Type the server IP address exactly to confirm permanent destruction.',
                ]);

                try {
                    $providerResult = $infrastructure->destroyByoCloud($server, $forceDestroy);
                    $remoteAlreadyDeleted = ($providerResult['status'] ?? null) === 'already_deleted_at_provider';
                } catch (Throwable $exception) {
                    report($exception);

                    if ($infrastructure->byoCloudResourceDeleted($server)) {
                        $providerResult = ['status' => 'already_deleted_at_provider'];
                        $remoteAlreadyDeleted = true;
                    } else {
                        return redirect()
                            ->route('servers.index')
                            ->with('error', 'The cloud provider did not confirm destruction of '.$name.'. Nothing was removed from Uplary. Try "Force destroy droplet now" or, if it was already deleted at DigitalOcean, use "Remove local record only". '.$exception->getMessage());
                    }
                }
            }
        }

        DB::transaction(function () use ($server, $request, $context, $cleanup, $destroysRemoteResources, $remoteAlreadyDeleted, $providerResult): void {
            ActivityLog::create([
                'tenant_id' => $context->id(),
                'user_id' => $request->user()->id,
                'action' => 'server.deleted',
                'description' => $destroysRemoteResources
                    ? ($remoteAlreadyDeleted
                        ? $server->name.' stale control-plane record removed after provider deletion'
                        : $server->name.' and its associated remote cloud resources permanently destroyed')
                    : $server->name.' removed from the control plane',
                'subject_type' => Server::class,
                'subject_id' => $server->id,
                'ip_address' => $request->ip(),
                'metadata' => $destroysRemoteResources ? [
                    'provider' => $server->provider->value,
                    'provider_resource_id' => $server->provider_resource_id,
                    'server_ip_address' => $server->ip_address,
                    'remote_already_deleted' => $remoteAlreadyDeleted,
                    'provider_result' => $providerResult,
                ] : null,
            ]);

            $server->delete();
            $cleanup->purge($server);
        });

        $message = $destroysRemoteResources
            ? ($remoteAlreadyDeleted
                ? $name.' was already deleted at the provider. Its stale Uplary record and local inventory were removed.'
                : $name.' ('.$ipAddress.') and its associated remote data were permanently destroyed. Its provider IP addresses were released.')
            : $name.' was removed from the control plane. Persistent remote data was not deleted.';

        return redirect()->route('servers.index')->with('success', $message);
    }

    private function tenantServer(Server $server, TenantContext $context): Server
    {
        abort_unless($server->tenant_id === $context->id(), 404);

        return $server;
    }

    private function uptimeLabel(Server $server): string
    {
        $since = $server->provisioned_at ?? $server->last_seen_at;
        if (! $since instanceof CarbonInterface) {
            return '—';
        }

        if ($server->status !== ServerStatus::Online && ! $server->provisioned_at) {
            return '—';
        }

        $interval = $since->diff(now());
        if ($interval->days > 0) {
            return $interval->days.'d '.$interval->h.'h';
        }
        if ($interval->h > 0) {
            return $interval->h.'h '.$interval->i.'m';
        }

        return max(1, $interval->i).'m';
    }

    /**
     * @return list<array{name: string, detail: string, icon: string, label: string, tone: string, icon_tone: string}>
     */
    private function platformServices(Server $server, ?ServerMetric $latestMetric): array
    {
        $online = $server->status === ServerStatus::Online;

        $docker = $this->serviceState(
            installed: filled($server->docker_version),
            running: $online && filled($server->docker_version),
            skipped: $server->install_docker === false && blank($server->docker_version),
            detailWhenInstalled: $server->docker_version ?: 'Container runtime',
            fallbackDetail: 'Container runtime',
        );

        $compose = $this->serviceState(
            installed: filled($server->docker_compose_version),
            running: $online && filled($server->docker_compose_version),
            skipped: $server->install_docker === false && blank($server->docker_compose_version),
            detailWhenInstalled: $server->docker_compose_version ? 'v'.$server->docker_compose_version : 'Application orchestration',
            fallbackDetail: 'Application orchestration',
        );

        $proxyStatus = (string) ($server->proxy_status ?? 'not_installed');
        $proxyInstalled = filled($server->proxy_installed_at) || in_array($proxyStatus, ['running', 'configured', 'failed'], true);
        $proxy = $this->serviceState(
            installed: $proxyInstalled,
            running: $online && $proxyStatus === 'running',
            skipped: $server->install_proxy === false && ! $proxyInstalled,
            detailWhenInstalled: $server->proxy_version ?: 'Routing and SSL',
            fallbackDetail: 'Routing and SSL',
            failed: $proxyStatus === 'failed',
            pendingLabel: $proxyInstalled ? ucfirst(str_replace('_', ' ', $proxyStatus)) : null,
        );

        $monitoringInstalled = $latestMetric !== null
            || $server->metrics->isNotEmpty()
            || ($server->install_monitoring === true && $server->provisioned_at !== null);
        $monitoring = $this->serviceState(
            installed: $monitoringInstalled,
            running: $online && $monitoringInstalled,
            skipped: $server->install_monitoring === false && ! $monitoringInstalled,
            detailWhenInstalled: 'Health monitoring',
            fallbackDetail: 'Health monitoring',
        );

        return [
            ['name' => 'Docker Engine', 'icon' => 'box', ...$docker],
            ['name' => 'Docker Compose', 'icon' => 'layers-3', ...$compose],
            ['name' => 'Traefik Proxy', 'icon' => 'route', ...$proxy],
            ['name' => 'Metrics Agent', 'icon' => 'activity', ...$monitoring],
        ];
    }

    /**
     * @return array{detail: string, label: string, tone: string, icon_tone: string}
     */
    private function serviceState(
        bool $installed,
        bool $running,
        bool $skipped,
        string $detailWhenInstalled,
        string $fallbackDetail,
        bool $failed = false,
        ?string $pendingLabel = null,
    ): array {
        if ($failed) {
            return ['detail' => $detailWhenInstalled, 'label' => 'Failed', 'tone' => 'failed', 'icon_tone' => 'orange'];
        }
        if ($running) {
            return ['detail' => $detailWhenInstalled, 'label' => 'Running', 'tone' => 'success', 'icon_tone' => 'green'];
        }
        if ($skipped) {
            return ['detail' => $fallbackDetail, 'label' => 'Skipped', 'tone' => 'warning', 'icon_tone' => 'orange'];
        }
        if ($installed) {
            return [
                'detail' => $detailWhenInstalled,
                'label' => $pendingLabel ?: 'Installed',
                'tone' => 'warning',
                'icon_tone' => 'blue',
            ];
        }

        return ['detail' => $fallbackDetail, 'label' => 'Not installed', 'tone' => 'warning', 'icon_tone' => 'orange'];
    }

    /**
     * @param  Collection<int, ServerMetric>  $metrics
     * @return array{has_data: bool, cpu_points: string, memory_points: string, axis: list<string>}
     */
    private function resourceChart(Collection $metrics): array
    {
        $width = 600;
        $height = 180;
        $padX = 8;
        $padTop = 20;
        $padBottom = 20;
        $plotWidth = $width - ($padX * 2);
        $plotHeight = $height - $padTop - $padBottom;
        $count = $metrics->count();

        if ($count === 0) {
            return [
                'has_data' => false,
                'cpu_points' => '',
                'memory_points' => '',
                'axis' => ['—', '—', '—'],
            ];
        }

        $step = $count > 1 ? $plotWidth / ($count - 1) : 0;
        $y = fn (float $percent): float => $padTop + $plotHeight - (min(100, max(0, $percent)) / 100 * $plotHeight);

        $cpuPoints = $metrics->values()->map(fn (ServerMetric $metric, int $index) => round($padX + ($index * $step), 2).','.round($y((float) $metric->cpu_percent), 2))->join(' ');
        $memoryPoints = $metrics->values()->map(fn (ServerMetric $metric, int $index) => round($padX + ($index * $step), 2).','.round($y((float) $metric->memory_percent), 2))->join(' ');

        $axisIndexes = $count === 1
            ? [0]
            : array_values(array_unique([0, (int) floor(($count - 1) / 2), $count - 1]));

        $axis = collect($axisIndexes)
            ->map(fn (int $index) => $metrics->values()[$index]?->recorded_at?->format($count > 12 ? 'H:i' : 'g A') ?: '—')
            ->all();

        return [
            'has_data' => true,
            'cpu_points' => $cpuPoints,
            'memory_points' => $memoryPoints,
            'axis' => $axis,
        ];
    }
}
