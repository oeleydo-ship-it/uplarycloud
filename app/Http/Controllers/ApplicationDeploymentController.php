<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Events\DeploymentProgressed;
use App\Http\Requests\StoreApplicationDeploymentRequest;
use App\Jobs\ProcessApplicationDeploymentJob;
use App\Jobs\ProcessWebApplicationDeploymentJob;
use App\Jobs\RollbackApplicationDeploymentJob;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationCategory;
use App\Models\ApplicationDeployment;
use App\Models\DeploymentRelease;
use App\Models\Server;
use App\Services\Applications\CatalogEnvironmentFactory;
use App\Services\Billing\PlanLimitService;
use App\Services\Deployments\DeploymentService;
use App\Services\Deployments\WebApplicationDeploymentService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ApplicationDeploymentController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $pricing = in_array($request->pricing, ['free', 'freemium', 'paid'], true) ? $request->pricing : null;
        $apps = Application::query()->where('active', true)->with('category')->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x->where('name', 'like', '%'.$request->search.'%')->orWhere('description', 'like', '%'.$request->search.'%')))->when($request->filled('category'), fn ($q) => $q->whereHas('category', fn ($x) => $x->where('slug', $request->category)))->when($pricing, fn ($q) => $q->where('pricing_model', $pricing))->orderByDesc('featured')->orderByDesc('install_count')->get();
        $recentDeployments = ApplicationDeployment::where('tenant_id', $context->id())
            ->with(['application', 'server'])
            ->latest()
            ->limit(5)
            ->get();

        return view('applications.index', ['applications' => $apps, 'categories' => ApplicationCategory::orderBy('position')->get(), 'recentDeployments' => $recentDeployments]);
    }

    public function installed(Request $request, TenantContext $context): View
    {
        $tenantId = $context->id();
        $baseQuery = ApplicationDeployment::where('tenant_id', $tenantId);
        $counts = [
            'all' => (clone $baseQuery)->count(),
            'running' => (clone $baseQuery)->where('status', DeploymentStatus::Running)->count(),
            'active' => (clone $baseQuery)->whereIn('status', [DeploymentStatus::Queued, DeploymentStatus::Deploying, DeploymentStatus::RollingBack])->count(),
            'failed' => (clone $baseQuery)->where('status', DeploymentStatus::Failed)->count(),
        ];
        $deployments = (clone $baseQuery)->with(['application.category', 'buildPack', 'server' => fn ($q) => $q->withTrashed()])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x->where('name', 'like', '%'.$request->search.'%')->orWhere('domain', 'like', '%'.$request->search.'%')->orWhere('docker_image', 'like', '%'.$request->search.'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('server'), fn ($q) => $q->where('server_id', $request->integer('server')))
            ->when($request->sort === 'oldest', fn ($q) => $q->oldest())
            ->when($request->sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when(! in_array($request->sort, ['oldest', 'name'], true), fn ($q) => $q->latest())
            ->paginate(12)
            ->withQueryString();
        $servers = Server::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']);

        return view('applications.installed', ['deployments' => $deployments, 'counts' => $counts, 'servers' => $servers]);
    }

    public function install(Application $application, TenantContext $context, PlanLimitService $limits): View
    {
        abort_unless($application->active, 404);
        $limits->enforceFeature($context->current(), 'marketplace');
        $limits->enforce($context->current(), 'applications');

        return $this->wizard($application, $context);
    }

    public function custom(TenantContext $context, PlanLimitService $limits): View
    {
        $limits->enforceFeature($context->current(), 'custom_docker');
        $limits->enforce($context->current(), 'applications');

        return $this->wizard(null, $context);
    }

    public function store(StoreApplicationDeploymentRequest $request, TenantContext $context, PlanLimitService $limits): RedirectResponse
    {
        $data = $request->validated();
        $limits->enforceDeployment($context->current(), $data['deployment_type'] ?? 'marketplace');
        $server = Server::where('tenant_id', $context->id())->findOrFail($data['server_id']);
        $this->authorize('operate', $server);
        if ($server->status !== ServerStatus::Online) {
            throw ValidationException::withMessages(['server_id' => 'Select an online server.']);
        }
        if (($data['memory_limit_mb'] ?? 0) > $server->memory_mb || ($data['disk_limit_gb'] ?? 0) > $server->disk_gb) {
            throw ValidationException::withMessages(['server_id' => 'The selected server does not meet the requested resources.']);
        }
        $deployment = DB::transaction(function () use ($data, $request, $server, $context): ApplicationDeployment {
            $deployment = ApplicationDeployment::create(['tenant_id' => $context->id(), 'application_id' => $data['application_id'] ?? null, 'server_id' => $server->id, 'created_by' => $request->user()->id, 'name' => $data['name'], 'deployment_type' => $data['deployment_type'], 'description' => $data['description'] ?? null, 'docker_image' => $data['docker_image'], 'docker_tag' => $data['docker_tag'], 'container_port' => $data['container_port'] ?? null, 'domain' => $data['domain'] ?? null, 'cpu_limit' => $data['cpu_limit'] ?? null, 'memory_limit_mb' => $data['memory_limit_mb'] ?? null, 'disk_limit_gb' => $data['disk_limit_gb'] ?? null, 'auto_start' => $request->boolean('auto_start', true), 'backup_enabled' => $request->boolean('backup_enabled'), 'restart_policy' => $data['restart_policy']]);
            foreach (array_values(DeploymentService::STAGES) as $position => $name) {
                $keys = array_keys(DeploymentService::STAGES);
                $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1]);
            }
            foreach ($data['environment_keys'] ?? [] as $index => $key) {
                if ($key) {
                    $deployment->environmentVariables()->create(['key' => $key, 'value' => $data['environment_values'][$index] ?? '', 'secret' => in_array((string) $index, $data['environment_secrets'] ?? [], true), 'description' => $data['environment_descriptions'][$index] ?? null]);
                }
            }

            return $deployment;
        });
        ProcessApplicationDeploymentJob::dispatch($deployment->id, $context->id(), $request->user()->id);
        event(new DeploymentProgressed($deployment->tenant_id, $deployment->uuid, 'queued', 0, 'queued'));

        return redirect()->route('deployments.show', $deployment)->with('success', 'Deployment queued.');
    }

    public function show(ApplicationDeployment $deployment, TenantContext $context): View
    {
        $this->guard($deployment, $context);
        $this->authorize('view', $deployment->server);

        return view('applications.show', ['deployment' => $deployment->load(['application.category', 'buildPack', 'server', 'steps', 'logs', 'environmentVariables', 'releases'])]);
    }

    public function status(ApplicationDeployment $deployment, TenantContext $context): JsonResponse
    {
        $this->guard($deployment, $context);

        return response()->json(['status' => $deployment->status->value, 'progress' => $deployment->progress, 'stage' => $deployment->current_stage, 'error' => $deployment->last_error, 'steps' => $deployment->steps()->get(['key', 'name', 'status', 'error']), 'logs' => $deployment->logs()->latest('occurred_at')->limit(100)->get()->reverse()->values()->map(fn ($log) => ['level' => $log->level, 'message' => $log->message, 'time' => $log->occurred_at->format('H:i:s')])]);
    }

    public function verify(ApplicationDeployment $deployment, TenantContext $context, DeploymentService $deployments): RedirectResponse
    {
        $this->guard($deployment, $context);
        $this->authorize('operate', $deployment->server);

        $result = $deployments->verifyRuntime($deployment);

        if ($result['ok']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function redeploy(Request $request, ApplicationDeployment $deployment, TenantContext $context): RedirectResponse
    {
        $this->guard($deployment, $context);
        $this->authorize('operate', $deployment->server);

        if (in_array($deployment->status, [DeploymentStatus::Deploying, DeploymentStatus::RollingBack], true)) {
            return back()->with('error', 'This deployment is already running.');
        }

        $isGit = in_array($deployment->deployment_type, ['web', 'git'], true);
        $stages = $isGit ? WebApplicationDeploymentService::STAGES : DeploymentService::STAGES;

        // Allow Retry for orphaned "queued" rows (DB queued, Redis job missing) as well as failed/running redeploys.
        foreach (array_values($stages) as $position => $name) {
            $key = array_keys($stages)[$position];
            $deployment->steps()->updateOrCreate(
                ['key' => $key],
                ['name' => $name, 'position' => $position + 1, 'status' => 'pending', 'started_at' => null, 'completed_at' => null, 'error' => null]
            );
        }

        $wasQueued = $deployment->status === DeploymentStatus::Queued;
        $deployment->releases()->where('is_current', true)->update(['is_current' => false]);
        $deployment->update([
            'status' => DeploymentStatus::Queued,
            'progress' => 0,
            'current_stage' => 'queued',
            'build_status' => $isGit ? 'queued' : $deployment->build_status,
            'last_error' => null,
            'completed_at' => null,
        ]);

        if ($isGit) {
            ProcessWebApplicationDeploymentJob::dispatch($deployment->id, $context->id(), $request->user()->id);
        } else {
            ProcessApplicationDeploymentJob::dispatch($deployment->id, $context->id(), $request->user()->id);
        }
        event(new DeploymentProgressed($deployment->tenant_id, $deployment->uuid, 'queued', 0, 'queued'));

        return back()->with('success', $wasQueued ? 'Deployment re-queued.' : 'Redeployment queued.');
    }

    public function rollback(Request $request, ApplicationDeployment $deployment, DeploymentRelease $release, TenantContext $context): RedirectResponse
    {
        $this->guard($deployment, $context);
        $this->authorize('operate', $deployment->server);
        abort_unless($release->application_deployment_id === $deployment->id, 404);
        if ($release->is_current) {
            return back()->withErrors(['rollback' => 'That release is already active.']);
        } RollbackApplicationDeploymentJob::dispatch($deployment->id, $release->id, $context->id(), $request->user()->id);
        event(new DeploymentProgressed($deployment->tenant_id, $deployment->uuid, 'rolling_back', $deployment->progress, 'rollback'));

        return back()->with('success', 'Rollback queued.');
    }

    public function destroy(Request $request, ApplicationDeployment $deployment, TenantContext $context): RedirectResponse
    {
        $this->guard($deployment, $context);
        $this->authorize('operate', $deployment->server);

        $name = $deployment->name;

        DB::transaction(function () use ($deployment, $request, $context): void {
            $deployment->containers()->get()->each->delete();
            $deployment->domains()->delete();

            ActivityLog::create([
                'tenant_id' => $context->id(),
                'user_id' => $request->user()->id,
                'action' => 'deployment.deleted',
                'description' => $deployment->name.' removed from the control plane',
                'subject_type' => ApplicationDeployment::class,
                'subject_id' => $deployment->id,
                'ip_address' => $request->ip(),
            ]);

            $deployment->delete();
        });

        return redirect()
            ->route('applications.installed')
            ->with('success', $name.' was removed from the control plane. Remote containers and volumes on the server were not deleted.');
    }

    private function wizard(?Application $application, TenantContext $context): View
    {
        $servers = Server::where('tenant_id', $context->id())->with('metrics')->orderByDesc('status')->get();
        $application = $application?->load(['template', 'category']);

        return view('applications.wizard', [
            'application' => $application,
            'servers' => $servers,
            'environment' => app(CatalogEnvironmentFactory::class)->forApplication($application),
        ]);
    }

    private function guard(ApplicationDeployment $deployment,TenantContext $context): void
    {
        abort_unless($deployment->tenant_id === $context->id(),404);
    }
}
