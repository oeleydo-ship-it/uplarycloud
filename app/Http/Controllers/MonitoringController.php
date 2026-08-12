<?php

namespace App\Http\Controllers;

use App\Jobs\CollectOperationsMetricsJob;
use App\Jobs\EvaluateAlertRulesJob;
use App\Models\AlertIncident;
use App\Models\ApplicationDeployment;
use App\Models\DockerContainer;
use App\Models\Server;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $filters = $request->validate([
            'range' => ['nullable', 'in:1h,6h,24h,7d,30d'],
            'server' => ['nullable', 'integer'],
            'site' => ['nullable', 'integer'],
        ]);

        $range = $filters['range'] ?? '24h';
        $since = match ($range) {
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        $tenantId = $context->id();
        $serverOptions = Server::where('tenant_id', $tenantId)->orderBy('name')->get();
        $sites = ApplicationDeployment::where('tenant_id', $tenantId)
            ->with('server')
            ->orderBy('name')
            ->get()
            ->map(fn (ApplicationDeployment $deployment) => [
                'id' => $deployment->id,
                'name' => $deployment->name,
                'domain' => $deployment->domain,
                'label' => $deployment->domain ?: $deployment->name,
                'server_id' => $deployment->server_id,
            ]);

        $serverId = isset($filters['server']) ? (int) $filters['server'] : null;
        $siteId = isset($filters['site']) ? (int) $filters['site'] : null;
        $selectedSite = $siteId ? $sites->firstWhere('id', $siteId) : null;

        if ($selectedSite && (! $serverId || $serverId !== (int) $selectedSite['server_id'])) {
            $serverId = (int) $selectedSite['server_id'];
        }

        $servers = Server::where('tenant_id', $tenantId)
            ->when($serverId, fn ($query) => $query->whereKey($serverId))
            ->with(['metrics' => fn ($query) => $query->where('recorded_at', '>=', $since)->orderBy('recorded_at')])
            ->orderBy('name')
            ->get();

        $containers = DockerContainer::where('tenant_id', $tenantId)
            ->when($serverId, fn ($query) => $query->where('server_id', $serverId))
            ->when($siteId, fn ($query) => $query->where('application_deployment_id', $siteId))
            ->with([
                'server' => fn ($q) => $q->withTrashed(),
                'deployment' => fn ($q) => $q->withTrashed(),
                'metrics' => fn ($query) => $query->where('recorded_at', '>=', $since)->orderBy('recorded_at'),
            ])
            ->orderByDesc('cpu_percent')
            ->get();

        $topContainers = $containers->take(8);
        $useContainerMetrics = (bool) $siteId;
        $chart = $this->buildChart($servers, $containers, $range, $since, $useContainerMetrics);
        $summary = $this->buildSummary($servers, $containers, $useContainerMetrics);
        $incidents = AlertIncident::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->whereHas('rule', function ($query) use ($serverId, $siteId) {
                if ($serverId) {
                    $query->where('server_id', $serverId);
                }
                if ($siteId) {
                    $query->where('application_deployment_id', $siteId);
                }
            })
            ->with('rule')
            ->latest('triggered_at')
            ->limit(5)
            ->get();

        $selectedServer = $serverId ? $serverOptions->firstWhere('id', $serverId) : null;
        $contextLabel = $this->contextLabel($selectedServer, $selectedSite);

        return view('operations.monitoring', compact(
            'servers',
            'serverOptions',
            'sites',
            'summary',
            'chart',
            'containers',
            'topContainers',
            'incidents',
            'range',
            'serverId',
            'siteId',
            'selectedServer',
            'selectedSite',
            'contextLabel',
        ));
    }

    public function collect(Request $request, TenantContext $context): RedirectResponse
    {
        $server = Server::where('tenant_id', $context->id())->firstOrFail();
        $this->authorize('operate', $server);

        Server::where('tenant_id', $context->id())
            ->where('status', 'online')
            ->pluck('id')
            ->each(fn ($id) => CollectOperationsMetricsJob::dispatch($id));

        EvaluateAlertRulesJob::dispatch($context->id());

        return back()->with('success', 'Fresh metric collection queued.');
    }

    private function buildSummary(Collection $servers, Collection $containers, bool $useContainerMetrics): array
    {
        if ($useContainerMetrics && $containers->isNotEmpty()) {
            return [
                'cpu' => round($containers->avg('cpu_percent') ?? 0, 1),
                'memory' => round($containers->avg(fn ($container) => $container->memory_limit_mb > 0
                    ? ($container->memory_usage_mb / $container->memory_limit_mb) * 100
                    : 0) ?? 0, 1),
                'disk' => round($servers->map(fn ($server) => $server->metrics->last()?->disk_percent ?? 0)->avg() ?? 0, 1),
                'online' => $servers->where('status.value', 'online')->count(),
                'servers' => $servers->count(),
                'scope' => 'site',
            ];
        }

        $latest = $servers->map(fn ($server) => $server->metrics->last())->filter();

        return [
            'cpu' => round($latest->avg('cpu_percent') ?? 0, 1),
            'memory' => round($latest->avg('memory_percent') ?? 0, 1),
            'disk' => round($latest->avg('disk_percent') ?? 0, 1),
            'online' => $servers->where('status.value', 'online')->count(),
            'servers' => $servers->count(),
            'scope' => 'server',
        ];
    }

    private function buildChart(Collection $servers, Collection $containers, string $range, Carbon $since, bool $useContainerMetrics): Collection
    {
        $metrics = $useContainerMetrics
            ? $containers->flatMap->metrics
            : $servers->flatMap->metrics;

        $containerLimits = $useContainerMetrics
            ? $containers->keyBy('id')->map(fn ($container) => max(1, (int) $container->memory_limit_mb))
            : collect();

        return collect(range(0, $this->bucketCount($range) - 1))->map(function (int $offset) use ($metrics, $range, $useContainerMetrics, $containerLimits) {
            [$at, $next, $label] = $this->bucketWindow($range, $offset);

            $points = $metrics->filter(fn ($metric) => $metric->recorded_at >= $at && $metric->recorded_at < $next);

            $memory = $useContainerMetrics
                ? round($points->avg(fn ($metric) => ($metric->memory_usage_mb / ($containerLimits[$metric->docker_container_id] ?? 1)) * 100) ?? 0, 1)
                : round($points->avg('memory_percent') ?? 0, 1);

            return [
                'label' => $label,
                'cpu' => round($points->avg('cpu_percent') ?? 0, 1),
                'memory' => $memory,
                'network' => round(($points->sum('network_in_bytes') + $points->sum('network_out_bytes')) / 1048576, 1),
            ];
        });
    }

    private function bucketCount(string $range): int
    {
        return match ($range) {
            '1h', '6h' => 12,
            '7d' => 7,
            '30d' => 30,
            default => 24,
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function bucketWindow(string $range, int $offset): array
    {
        $bucketCount = $this->bucketCount($range);
        $reverseOffset = $bucketCount - 1 - $offset;

        return match ($range) {
            '1h' => [
                $at = now()->subMinutes($reverseOffset * 5),
                $at->copy()->addMinutes(5),
                $at->format('H:i'),
            ],
            '6h' => [
                $at = now()->subMinutes($reverseOffset * 30),
                $at->copy()->addMinutes(30),
                $at->format('H:i'),
            ],
            '7d' => [
                $at = now()->subDays($reverseOffset)->startOfDay(),
                $at->copy()->addDay(),
                $at->format('M j'),
            ],
            '30d' => [
                $at = now()->subDays($reverseOffset)->startOfDay(),
                $at->copy()->addDay(),
                $at->format('M j'),
            ],
            default => [
                $at = now()->subHours($reverseOffset),
                $at->copy()->addHour(),
                $at->format('H:i'),
            ],
        };
    }

    private function contextLabel(?Server $selectedServer, ?array $selectedSite): string
    {
        if ($selectedSite) {
            return $selectedSite['label'].' on '.($selectedServer?->name ?? 'server');
        }

        if ($selectedServer) {
            return $selectedServer->name;
        }

        return 'All servers and sites';
    }
}
