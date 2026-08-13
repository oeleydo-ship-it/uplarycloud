<?php

namespace App\Http\Controllers;

use App\Enums\ContainerStatus;
use App\Events\DockerResourceUpdated;
use App\Jobs\CollectOperationsMetricsJob;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\DockerContainer;
use App\Models\Server;
use App\Services\Docker\ContainerInventoryService;
use App\Services\Docker\DockerService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DockerContainerController extends Controller
{
    public function index(Request $request, TenantContext $ctx, ContainerInventoryService $inventory): View
    {
        $tenant = $ctx->current();
        $tenantId = $ctx->id();
        $inventory->linkDeployments($tenant);

        $baseQuery = DockerContainer::query()->where('tenant_id', $tenantId);
        $counts = [
            'all' => (clone $baseQuery)->count(),
            'running' => (clone $baseQuery)->where('status', ContainerStatus::Running)->count(),
            'stopped' => (clone $baseQuery)->whereIn('status', [ContainerStatus::Stopped, ContainerStatus::Exited, ContainerStatus::Paused])->count(),
            'restarting' => (clone $baseQuery)->where('status', ContainerStatus::Restarting)->count(),
        ];

        $servers = Server::query()->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'status', 'location']);
        $applications = Application::query()
            ->whereHas('deployments', fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $query = (clone $baseQuery)
            ->with([
                'server' => fn ($q) => $q->withTrashed(),
                'deployment' => fn ($q) => $q->withTrashed(),
                'deployment.application',
                'deployment.buildPack',
            ])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$request->search.'%')
                ->orWhere('image', 'like', '%'.$request->search.'%')
                ->orWhere('docker_id', 'like', '%'.$request->search.'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('server'), fn ($q) => $q->where('server_id', $request->integer('server')))
            ->when($request->filled('application'), fn ($q) => $q->whereHas('deployment', fn ($deployment) => $deployment->where('application_id', $request->application)))
            ->when($request->sort === 'oldest', fn ($q) => $q->oldest())
            ->when($request->sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when(! in_array($request->sort, ['oldest', 'name'], true), fn ($q) => $q->latest());

        $containers = $query->paginate(12)->withQueryString();

        $contextServer = $request->filled('server')
            ? $servers->firstWhere('id', $request->integer('server'))
            : $servers->first(fn (Server $server) => $server->status->value === 'online') ?? $servers->first();

        return view('docker.containers', [
            'containers' => $containers,
            'counts' => $counts,
            'servers' => $servers,
            'applications' => $applications,
            'contextServer' => $contextServer,
        ]);
    }

    public function sync(Request $request, TenantContext $ctx, ContainerInventoryService $inventory): RedirectResponse
    {
        $data = $request->validate(['server' => ['nullable', 'exists:servers,id']]);
        $servers = Server::query()
            ->where('tenant_id', $ctx->id())
            ->when(! empty($data['server']), fn ($q) => $q->whereKey($data['server']))
            ->where('status', 'online')
            ->get();

        foreach ($servers as $server) {
            $this->authorize('operate', $server);
        }

        try {
            $updated = $inventory->syncTenant($ctx->current(), $data['server'] ?? null);
            CollectOperationsMetricsJob::dispatch($data['server'] ?? null);

            return back()->with('success', "Container sync completed ({$updated} refreshed).");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Container sync failed: '.$exception->getMessage());
        }
    }

    public function prune(Request $request, TenantContext $ctx, DockerService $docker): RedirectResponse
    {
        $data = $request->validate(['server' => ['nullable', 'exists:servers,id']]);
        $servers = Server::query()
            ->where('tenant_id', $ctx->id())
            ->when(! empty($data['server']), fn ($q) => $q->whereKey($data['server']))
            ->where('status', 'online')
            ->get();

        foreach ($servers as $server) {
            $this->authorize('operate', $server);
            $docker->pruneContainers($server);
        }

        return back()->with('success', 'Unused container cleanup completed.');
    }

    public function action(Request $request, DockerContainer $container, TenantContext $ctx, ContainerInventoryService $inventory, DockerService $docker): RedirectResponse
    {
        abort_unless($container->tenant_id === $ctx->id(), 404);

        $server = $container->server;
        if (! $server || $server->trashed()) {
            return back()->with('error', 'This container cannot be managed because its server is unavailable.');
        }

        $this->authorize('operate', $server);

        $action = $request->validate([
            'action' => ['required', 'in:start,stop,restart,pause,unpause,remove,inspect'],
        ])['action'];

        try {
            if ($action === 'inspect') {
                $inventory->refreshOne($container);

                return back()->with('success', 'Container status refreshed from Docker.');
            }

            $docker->container($container, $action);

            ActivityLog::create([
                'tenant_id' => $ctx->id(),
                'user_id' => $request->user()->id,
                'action' => 'docker.container.'.$action,
                'description' => 'Container '.$action.' completed',
                'subject_type' => DockerContainer::class,
                'subject_id' => $container->id,
                'created_at' => now(),
            ]);
            event(new DockerResourceUpdated($ctx->id(), 'container', $container->uuid, $action, 'completed'));

            return back()->with('success', $this->actionSuccessMessage($action, $container->name));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Unable to '.$action.' container: '.$exception->getMessage());
        }
    }

    private function actionSuccessMessage(string $action, string $name): string
    {
        return match ($action) {
            'start' => $name.' started.',
            'stop' => $name.' stopped.',
            'restart' => $name.' restarted.',
            'pause' => $name.' paused.',
            'unpause' => $name.' unpaused.',
            'remove' => $name.' removed.',
            default => ucfirst($action).' completed.',
        };
    }
}
