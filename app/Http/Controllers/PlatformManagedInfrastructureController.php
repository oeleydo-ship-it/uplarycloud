<?php

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Jobs\CreateManagedServerJob;
use App\Models\InfrastructureOperation;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;
use App\Services\Billing\PlanLimitService;
use App\Services\Servers\ControlPlaneKeyService;
use App\Support\PlatformSettings;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformManagedInfrastructureController extends Controller
{
    public function index(Request $request, TenantContext $context, PlatformSettings $settings): View
    {
        $this->manage($request);
        abort_unless($settings->managedServersEnabled(), 404);
        app(PlanLimitService::class)->enforceFeature($context->current(), 'managed_servers');

        $tenant = $context->current();
        $connections = ProviderConnection::query()
            ->where('platform_managed', true)
            ->where('active', true)
            ->whereNotNull('last_verified_at')
            ->get();
        $plans = ManagedServerPlan::query()
            ->where('active', true)
            ->whereIn('provider', $connections->pluck('provider'))
            ->orderBy('position')
            ->get()
            ->groupBy('provider');

        return view('managed.index', [
            'plans' => $plans,
            'connections' => $connections,
            'servers' => $tenant->servers()->where('server_type', 'managed')->with(['managedPlan', 'providerConnection'])->latest()->get(),
            'operations' => $tenant->infrastructureOperations()->with('server')->latest()->limit(12)->get(),
            'charges' => $tenant->infrastructureCharges()->with('server')->latest()->limit(12)->get(),
        ]);
    }

    public function store(Request $request, TenantContext $context, PlanLimitService $limits, ControlPlaneKeyService $keys, PlatformSettings $settings): RedirectResponse
    {
        $this->manage($request);
        abort_unless($settings->managedServersEnabled(), 404);
        $limits->enforceFeature($context->current(), 'managed_servers');

        $limits->enforce($context->current(), 'servers');
        $limits->enforce($context->current(), 'managed_servers');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('servers')->where('tenant_id', $context->id())],
            'provider_connection_id' => 'required|integer',
            'managed_server_plan_id' => 'required|integer',
            'region' => 'required|string|max:60',
            'image' => 'required|string|max:100',
        ]);

        $connection = ProviderConnection::query()
            ->where('platform_managed', true)
            ->where('active', true)
            ->whereNotNull('last_verified_at')
            ->findOrFail($data['provider_connection_id']);

        $plan = ManagedServerPlan::query()
            ->where('active', true)
            ->where('provider', $connection->provider)
            ->findOrFail($data['managed_server_plan_id']);

        if (! in_array($data['region'], $plan->regions, true) || ! in_array($data['image'], $plan->images ?? [], true)) {
            throw ValidationException::withMessages(['region' => 'This region or image is unavailable.']);
        }

        $key = $keys->generate();

        [$server, $operation] = DB::transaction(function () use ($data, $connection, $plan, $context, $request, $key) {
            $server = Server::create([
                'tenant_id' => $context->id(),
                'provider_connection_id' => $connection->id,
                'managed_server_plan_id' => $plan->id,
                'name' => $data['name'],
                'provider' => $connection->provider,
                'ip_address' => '0.0.0.0',
                'location' => strtoupper($data['region']),
                'provider_region' => $data['region'],
                'operating_system' => $data['image'],
                'provider_image' => $data['image'],
                'server_type' => 'managed',
                'status' => ServerStatus::Pending,
                'authentication_method' => 'ssh_key',
                'ssh_username' => \App\Services\Infrastructure\ManagedInfrastructureService::PROVISIONING_SSH_USER,
                'cpu_cores' => $plan->cpu_cores,
                'memory_mb' => $plan->memory_mb,
                'disk_gb' => $plan->disk_gb,
                'install_docker' => true,
                'install_proxy' => true,
                'install_monitoring' => true,
            ]);
            $server->credential()->create(['private_key' => $key['private_key']]);
            foreach ([
                'connect' => 'Connecting to cloud server',
                'system' => 'Checking system',
                'docker' => 'Installing Docker',
                'configure' => 'Configuring Docker',
                'proxy' => 'Installing reverse proxy',
                'monitoring' => 'Configuring monitoring',
                'verify' => 'Final verification',
            ] as $stepKey => $label) {
                $server->provisioningSteps()->create([
                    'key' => $stepKey,
                    'label' => $label,
                    'position' => $server->provisioningSteps()->count() + 1,
                ]);
            }
            $op = InfrastructureOperation::create([
                'tenant_id' => $context->id(),
                'server_id' => $server->id,
                'requested_by' => $request->user()->id,
                'action' => 'create',
                'status' => 'pending',
                'parameters' => [
                    'plan' => $plan->provider_plan_id,
                    'region' => $data['region'],
                    'image' => $data['image'],
                    'public_key' => $key['public_key'],
                    'monthly_price' => $plan->monthly_price,
                    'billing' => true,
                ],
            ]);

            return [$server, $op];
        });

        CreateManagedServerJob::dispatch($operation->id);

        return redirect()->route('servers.provisioning', $server)
            ->with('success', $server->name.' is being created and provisioned.');
    }

    private function manage(Request $request): void
    {
        $role = $request->user()->tenants()->whereKey(session('tenant_id'))->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403);
    }
}
