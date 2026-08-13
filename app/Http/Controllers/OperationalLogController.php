<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationDeployment;
use App\Models\OperationalLog;
use App\Models\Server;
use App\Services\Billing\PlanLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalLogController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $tenantId = $context->id();

        $rangeKey = (string) $request->input('range', '1h');
        $rangeStart = $this->rangeStart($rangeKey);
        $perPage = (int) $request->input('per_page', 50);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 50;
        }

        $serverId = $this->serverId($request);

        $logs = $this->query($request, $context, $rangeStart, $serverId)
            ->with([
                'server' => fn ($q) => $q->withTrashed(),
                'deployment' => fn ($q) => $q->withTrashed(),
                'container' => fn ($q) => $q->withTrashed(),
            ])
            ->latest('occurred_at')
            ->paginate($perPage)
            ->withQueryString();

        $statsBase = OperationalLog::query()
            ->where('tenant_id', $tenantId)
            ->where('occurred_at', '>=', $rangeStart)
            ->when($this->category($request), fn ($q, $category) => $q->where('category', $category))
            ->when($request->filled('source'), fn ($q) => $q->where('source', (string) $request->input('source')))
            ->when($request->filled('site'), fn ($q) => $q->where('application_deployment_id', (int) $request->input('site')))
            ->when($request->filled('application'), fn ($q) => $q->whereHas(
                'deployment',
                fn ($d) => $d->where('application_id', (int) $request->input('application'))
            ))
            ->when($serverId !== null, fn ($q) => $q->where('server_id', $serverId));

        $sizeBytes = (clone $statsBase)
            ->selectRaw('COALESCE(SUM(LENGTH(message)),0) AS total_bytes')
            ->value('total_bytes') ?? 0;

        $last24 = now()->subDay();
        $stats24Base = OperationalLog::query()
            ->where('tenant_id', $tenantId)
            ->when($this->category($request), fn ($q, $category) => $q->where('category', $category))
            ->when($request->filled('source'), fn ($q) => $q->where('source', (string) $request->input('source')))
            ->when($request->filled('site'), fn ($q) => $q->where('application_deployment_id', (int) $request->input('site')))
            ->when($request->filled('application'), fn ($q) => $q->whereHas(
                'deployment',
                fn ($d) => $d->where('application_id', (int) $request->input('application'))
            ))
            ->when($serverId !== null, fn ($q) => $q->where('server_id', $serverId));

        $counts = [
            'all' => (clone $statsBase)->count(),
            'size' => (float) $sizeBytes,
            'errors' => (clone $stats24Base)->where('occurred_at', '>=', $last24)->whereIn('severity', ['error', 'critical'])->count(),
            'warnings' => (clone $stats24Base)->where('occurred_at', '>=', $last24)->where('severity', 'warning')->count(),
        ];

        $servers = Server::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'status', 'location']);
        $sites = ApplicationDeployment::where('tenant_id', $tenantId)
            ->with(['server' => fn ($q) => $q->withTrashed(), 'application.category'])
            ->orderByDesc('deployed_at')
            ->get();
        $applications = Application::query()
            ->whereHas('deployments', fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get(['id', 'name']);

        $sources = OperationalLog::query()
            ->where('tenant_id', $tenantId)
            ->where('occurred_at', '>=', $rangeStart)
            ->when($this->category($request), fn ($q, $category) => $q->where('category', $category))
            ->when($request->filled('site'), fn ($q) => $q->where('application_deployment_id', (int) $request->input('site')))
            ->when($request->filled('application'), fn ($q) => $q->whereHas(
                'deployment',
                fn ($d) => $d->where('application_id', (int) $request->input('application'))
            ))
            ->when($serverId !== null, fn ($q) => $q->where('server_id', $serverId))
            ->select('source', DB::raw('COUNT(*) AS total'))
            ->groupBy('source')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn ($row) => ['source' => $row->source ?? 'System', 'total' => (int) $row->total]);

        $contextServer = null;
        if ($request->filled('site')) {
            $contextServer = ApplicationDeployment::where('tenant_id', $tenantId)
                ->with(['server' => fn ($q) => $q->withTrashed()])
                ->find((int) $request->input('site'))
                ?->server;
        } elseif ($serverId !== null) {
            $contextServer = $servers->firstWhere('id', $serverId)
                ?? Server::withTrashed()->where('tenant_id', $tenantId)->find($serverId);
        }
        $contextServer ??= $servers->first(fn (Server $s) => $s->status->value === 'online') ?? $servers->first();

        return view('operations.logs', [
            'logs' => $logs,
            'counts' => $counts,
            'servers' => $servers,
            'sites' => $sites,
            'applications' => $applications,
            'sources' => $sources,
            'contextServer' => $contextServer,
            'rangeKey' => $rangeKey,
        ]);
    }

    public function download(Request $request, TenantContext $context, PlanLimitService $limits): StreamedResponse
    {
        $limits->enforceFeature($context->current(), 'audit_exports');
        $rangeKey = (string) $request->input('range', '1h');
        $rangeStart = $this->rangeStart($rangeKey);
        $serverId = $this->serverId($request);

        $logs = $this->query($request, $context, $rangeStart, $serverId)
            ->latest('occurred_at')
            ->limit(10000)
            ->get();

        return response()->streamDownload(function () use ($logs): void {
            foreach ($logs as $log) {
                echo '['.$log->occurred_at->toIso8601String().'] ['.strtoupper($log->severity).'] ['.$log->category.'] '.$log->message.PHP_EOL;
            }
        }, 'operations-logs-'.now()->format('Ymd-His').'.log', ['Content-Type' => 'text/plain']);
    }

    private function query(Request $request, TenantContext $context, $rangeStart, ?int $serverId = null)
    {
        $tenantId = $context->id();
        $serverId ??= $this->serverId($request);
        $category = $this->category($request);
        $severity = $request->filled('severity') ? (string) $request->input('severity') : null;
        $siteId = $request->filled('site') ? (int) $request->input('site') : null;
        $applicationId = $request->filled('application') ? (int) $request->input('application') : null;

        return OperationalLog::where('tenant_id', $tenantId)
            ->where('occurred_at', '>=', $rangeStart)
            ->when($request->filled('search'), fn ($q) => $q->where('message', 'like', '%'.$request->input('search').'%'))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($severity, fn ($q) => $q->where('severity', $severity))
            ->when($request->filled('source'), fn ($q) => $q->where('source', (string) $request->input('source')))
            ->when($serverId !== null, fn ($q) => $q->where('server_id', $serverId))
            ->when($siteId !== null, fn ($q) => $q->where('application_deployment_id', $siteId))
            ->when($applicationId !== null, fn ($q) => $q->whereHas(
                'deployment',
                fn ($d) => $d->where('application_id', $applicationId)
            ));
    }

    /**
     * Resolve ?server= / ?server_id= from query input — never $request->server (Symfony bag).
     */
    private function serverId(Request $request): ?int
    {
        if ($request->filled('server_id')) {
            return (int) $request->input('server_id');
        }

        if ($request->filled('server')) {
            return (int) $request->input('server');
        }

        return null;
    }

    private function category(Request $request): ?string
    {
        if ($request->filled('type')) {
            return (string) $request->input('type');
        }

        if ($request->filled('category')) {
            return (string) $request->input('category');
        }

        return null;
    }

    private function rangeStart(string $rangeKey)
    {
        return match ($rangeKey) {
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subHour(),
        };
    }
}
