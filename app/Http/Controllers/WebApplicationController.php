<?php

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Events\DeploymentProgressed;
use App\Http\Requests\StoreWebApplicationRequest;
use App\Jobs\ProcessWebApplicationDeploymentJob;
use App\Models\ApplicationDeployment;
use App\Models\BuildPack;
use App\Models\Server;
use App\Services\Billing\PlanLimitService;
use App\Services\Deployments\BuildCommandValidator;
use App\Services\Deployments\WebApplicationDeploymentService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebApplicationController extends Controller
{
    public function create(TenantContext $context, PlanLimitService $limits): View
    {
        $limits->enforceFeature($context->current(), 'git_deploy');
        $limits->enforce($context->current(), 'applications');

        return view('applications.web-wizard', ['buildPacks' => BuildPack::where('active', true)->get(), 'servers' => Server::where('tenant_id', $context->id())->orderByDesc('status')->get()]);
    }

    public function store(StoreWebApplicationRequest $request, TenantContext $context, BuildCommandValidator $validator, PlanLimitService $limits): RedirectResponse
    {
        $limits->enforceDeployment($context->current(), 'git');
        $data = $request->validated();
        $pack = BuildPack::findOrFail($data['build_pack_id']);
        $server = Server::where('tenant_id', $context->id())->findOrFail($data['server_id']);
        $this->authorize('operate', $server);
        if ($server->status !== ServerStatus::Online) {
            throw ValidationException::withMessages(['server_id' => 'Select an online server.']);
        }if (($data['memory_limit_mb'] ?? 0) > $server->memory_mb || ($data['disk_limit_gb'] ?? 0) > $server->disk_gb) {
            throw ValidationException::withMessages(['server_id' => 'The selected server does not meet the requested resources.']);
        }$validator->repository($data['repository_url']);
        foreach (['install_command', 'build_command', 'start_command'] as $field) {
            $validator->validate($data[$field] ?? null, $field);
        }$defaults = $pack->defaults;
        $secret = Str::random(48);
        $enableRedis = $request->boolean('enable_redis') || $request->boolean('enable_queue') || $request->boolean('enable_reverb') || $request->boolean('enable_horizon');
        $d = DB::transaction(function () use ($data, $request, $context, $server, $pack, $defaults, $secret, $enableRedis) {
            $slug = Str::slug($data['name']).'-'.Str::lower(Str::random(5));
            $deployment = ApplicationDeployment::create(['tenant_id' => $context->id(), 'build_pack_id' => $pack->id, 'server_id' => $server->id, 'created_by' => $request->user()->id, 'name' => $data['name'], 'slug' => $slug, 'deployment_type' => 'git', 'framework' => $pack->framework, 'description' => $data['description'] ?? null, 'docker_image' => 'platform/'.$slug, 'docker_tag' => 'latest', 'container_port' => $data['container_port'], 'domain' => $data['domain'] ?? null, 'cpu_limit' => $data['cpu_limit'] ?? .5, 'memory_limit_mb' => $data['memory_limit_mb'] ?? 512, 'disk_limit_gb' => $data['disk_limit_gb'] ?? 2, 'restart_policy' => 'unless-stopped', 'git_provider' => $data['git_provider'], 'repository_url' => $data['repository_url'], 'branch' => $data['branch'], 'deploy_key' => $data['deploy_key'] ?? null, 'runtime_version' => $data['runtime_version'], 'root_directory' => $data['root_directory'], 'package_manager' => $data['package_manager'] ?? $defaults['package_manager'], 'install_command' => $data['install_command'] ?? $defaults['install_command'], 'build_command' => $data['build_command'] ?? $defaults['build_command'], 'start_command' => $data['start_command'] ?? $defaults['start_command'], 'output_directory' => $data['output_directory'] ?? $defaults['output_directory'] ?? null, 'database_engine' => $data['database_engine'] ?? null, 'enable_redis' => $enableRedis, 'enable_queue' => $request->boolean('enable_queue'), 'enable_scheduler' => $request->boolean('enable_scheduler'), 'enable_reverb' => $request->boolean('enable_reverb'), 'enable_horizon' => $request->boolean('enable_horizon'), 'auto_deploy' => $request->boolean('auto_deploy'), 'webhook_secret' => $secret, 'build_status' => 'queued']);
            foreach (array_values(WebApplicationDeploymentService::STAGES) as $position => $name) {
                $keys = array_keys(WebApplicationDeploymentService::STAGES);
                $deployment->steps()->create(['key' => $keys[$position], 'name' => $name, 'position' => $position + 1]);
            }foreach ($data['environment_keys'] ?? [] as $index => $key) {
                if ($key) {
                    $deployment->environmentVariables()->create(['key' => $key, 'value' => $data['environment_values'][$index] ?? '', 'secret' => in_array((string) $index, $data['environment_secrets'] ?? [], true)]);
                }
            }

return $deployment;
        });
        ProcessWebApplicationDeploymentJob::dispatch($d->id, $context->id(), $request->user()->id);
        event(new DeploymentProgressed($d->tenant_id, $d->uuid, 'queued', 0, 'queued'));

        return redirect()->route('deployments.show', $d)->with('success', 'Git build queued.')->with('webhook_secret',$request->boolean('auto_deploy') ? $secret : null);
    }
}
