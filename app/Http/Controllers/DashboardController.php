<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Server;
use App\Models\DockerContainer;
use App\Models\DockerVolume;
use App\Models\ApplicationDeployment;
use App\Support\TenantContext;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $context): View
    {
        $tenant = $context->current();
        $servers = $tenant->servers()
            ->withCount(['containers', 'applicationDeployments'])
            ->with(['metrics' => fn ($query) => $query->latest('recorded_at')->limit(1)])
            ->orderByRaw("case when status = 'online' then 0 else 1 end")
            ->orderBy('name')
            ->get();
        $primaryServer = $servers->first();
        $deploymentQuery = $tenant->deployments();
        $deploymentCount = (clone $deploymentQuery)->count();
        $runningDeploymentCount = (clone $deploymentQuery)->where('status', 'running')->count();
        $deployments = (clone $deploymentQuery)
            ->with(['application', 'server'])
            ->latest('deployed_at')
            ->limit(5)
            ->get();
        $containers = DockerContainer::query()->where('tenant_id', $tenant->id);
        $memoryUsedMb = (int) (clone $containers)->sum('memory_usage_mb');
        $memoryLimitMb = (int) (clone $containers)->sum('memory_limit_mb');
        $backupCount = $tenant->backups()->count();
        $lastBackup = $tenant->backups()->latest('completed_at')->first();

        return view('dashboard', [
            'tenant' => $tenant,
            'servers' => $servers,
            'primaryServer' => $primaryServer,
            'featuredApplications' => Application::query()->with('category')->where('active', true)->orderByDesc('featured')->orderByDesc('install_count')->limit(10)->get(),
            'deployments' => $deployments,
            'memoryUsedMb' => $memoryUsedMb,
            'memoryLimitMb' => $memoryLimitMb,
            'stats' => [
                ['label' => 'Servers', 'value' => $servers->count(), 'detail' => 'Online: '.$servers->filter(fn ($server) => $server->status->value === 'online')->count(), 'icon' => 'server', 'tone' => 'purple', 'href' => route('servers.index')],
                ['label' => 'Applications', 'value' => $deploymentCount, 'detail' => 'Running: '.$runningDeploymentCount, 'icon' => 'box', 'tone' => 'blue', 'href' => route('applications.index')],
                ['label' => 'Containers', 'value' => (clone $containers)->count(), 'detail' => 'Running: '.(clone $containers)->where('status', 'running')->count(), 'icon' => 'container', 'tone' => 'green', 'href' => route('containers.index')],
                ['label' => 'Volumes', 'value' => DockerVolume::query()->where('tenant_id', $tenant->id)->count(), 'detail' => 'Persistent storage', 'icon' => 'database', 'tone' => 'orange', 'href' => route('volumes.index')],
                ['label' => 'Backups', 'value' => $backupCount, 'detail' => $lastBackup?->completed_at?->diffForHumans() ?? 'No backups yet', 'icon' => 'cloud', 'tone' => 'purple', 'href' => route('backups.index')],
            ],
            'activities' => ActivityLog::where('tenant_id', $tenant->id)->with('user')->latest('created_at')->limit(6)->get(),
        ]);
    }
}
