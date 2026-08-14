<?php

namespace App\Http\Controllers;

use App\Jobs\DockerResourceActionJob;
use App\Models\Application;
use App\Models\DockerVolume;
use App\Models\Server;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DockerVolumeController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $tenantId = $context->id();
        $base = DockerVolume::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('server_id', Server::liveIdQuery($tenantId));

        $mountedIds = (clone $base)->whereHas('containers')->pluck('id');
        $totalBytes = (clone $base)->sum('size_bytes');

        $counts = [
            'all' => (clone $base)->count(),
            'storage_gb' => round($totalBytes / 1073741824, 1),
            'mounted' => $mountedIds->count(),
            'available' => (clone $base)->whereDoesntHave('containers')->count(),
            'backed_up' => (clone $base)->whereNotNull('backed_up_at')->count(),
        ];

        $volumes = (clone $base)
            ->with([
                'server' => fn ($q) => $q->withTrashed(),
                'containers.deployment' => fn ($q) => $q->withTrashed(),
                'containers.deployment.application',
            ])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$request->search.'%')
                ->orWhere('docker_name', 'like', '%'.$request->search.'%')
                ->orWhere('mountpoint', 'like', '%'.$request->search.'%')))
            ->when($request->filled('server'), fn ($q) => $q->where('server_id', $request->integer('server')))
            ->when($request->filled('mount'), function ($q) use ($request): void {
                $request->string('mount')->value() === 'mounted'
                    ? $q->whereHas('containers')
                    : $q->whereDoesntHave('containers');
            })
            ->when($request->filled('application'), function ($q) use ($request): void {
                $q->whereHas('containers.deployment', fn ($deployment) => $deployment->where('application_id', $request->integer('application')));
            })
            ->when($request->filled('driver'), fn ($q) => $q->where('driver', $request->string('driver')))
            ->when($request->sort === 'oldest', fn ($q) => $q->oldest())
            ->when($request->sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when($request->sort === 'size', fn ($q) => $q->orderByDesc('size_bytes'))
            ->when(! in_array($request->sort, ['oldest', 'name', 'size'], true), fn ($q) => $q->latest())
            ->paginate(12)
            ->withQueryString();

        $servers = Server::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'status']);
        $applications = Application::query()
            ->whereHas('deployments', fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
        $drivers = (clone $base)->distinct()->orderBy('driver')->pluck('driver');

        $contextServer = $request->filled('server')
            ? $servers->firstWhere('id', $request->integer('server'))
            : $servers->first(fn (Server $server) => $server->status->value === 'online') ?? $servers->first();

        return view('docker.volumes', compact('volumes', 'counts', 'servers', 'applications', 'drivers', 'contextServer'));
    }

    public function destroy(Request $request, DockerVolume $volume, TenantContext $context): RedirectResponse
    {
        abort_unless($volume->tenant_id === $context->id(), 404);
        $this->authorize('operate', $volume->server);

        if ($volume->containers()->exists()) {
            return back()->withErrors(['volume' => 'Detach all containers before removing this persistent volume.']);
        }

        $request->validate(['confirmation' => ['required', 'in:DELETE']]);
        DockerResourceActionJob::dispatch('volume', $volume->id, 'remove', $context->id(), $request->user()->id);

        return back()->with('success', 'Volume removal queued.');
    }
}
