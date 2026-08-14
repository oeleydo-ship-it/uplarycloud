<?php

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Jobs\CreateManagedServerJob;
use App\Jobs\ManagedServerActionJob;
use App\Models\ActivityLog;
use App\Models\InfrastructureOperation;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;
use App\Services\Billing\PlanLimitService;
use App\Services\Infrastructure\CloudProviderFactory;
use App\Services\Servers\ControlPlaneKeyService;
use App\Support\TenantContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ManagedInfrastructureController extends Controller
{
    public function cloudApi(Request $request, TenantContext $context): View
    {
        $this->manage($request);
        app(PlanLimitService::class)->enforceFeature($context->current(), 'cloud_api');
        $tenant = $context->current();

        return view('cloud-api.index', [
            'connections' => $tenant->providerConnections()
                ->where('platform_managed', false)
                ->latest()
                ->get(),
        ]);
    }

    public function connection(Request $request, TenantContext $context): RedirectResponse
    {
        $this->manage($request);
        app(PlanLimitService::class)->enforceFeature($context->current(), 'cloud_api');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'provider' => ['required', Rule::in(['digitalocean', 'hetzner'])],
            'api_token' => ['required', 'string', 'max:2000'],
            'api_secret' => ['nullable', 'string', 'max:2000'],
            'account_id' => ['nullable', 'string', 'max:255'],
        ]);

        $connection = ProviderConnection::create($data + [
            'tenant_id' => $context->id(),
            'active' => true,
            'platform_managed' => false,
        ]);

        ActivityLog::create([
            'tenant_id' => $context->id(),
            'user_id' => $request->user()->id,
            'action' => 'provider.connected',
            'description' => $connection->name.' provider credentials saved',
        ]);

        return back()->with('success', 'Provider credentials encrypted. Verify the connection before provisioning.');
    }

    public function verify(Request $request, ProviderConnection $connection, TenantContext $context, CloudProviderFactory $factory): RedirectResponse
    {
        $this->manage($request);
        $this->guardConnection($connection, $context);

        try {
            $factory->make($connection)->verify($connection);
            $connection->update(['last_verified_at' => now(), 'last_error' => null]);

            return back()->with('success', $connection->name.' verified successfully.');
        } catch (Throwable $e) {
            $connection->update(['last_error' => $e->getMessage()]);

            return back()->withErrors(['provider' => $e->getMessage()]);
        }
    }

    public function catalog(Request $request, ProviderConnection $connection, TenantContext $context, CloudProviderFactory $factory): JsonResponse
    {
        $this->manage($request);
        $this->guardConnection($connection, $context);
        abort_unless($connection->active && $connection->last_verified_at, 404);
        app(PlanLimitService::class)->enforceFeature($context->current(), 'cloud_api');

        try {
            $plans = array_values($factory->make($connection)->catalog($connection)['plans'] ?? []);
            if ($plans === []) {
                return response()->json([
                    'success' => false,
                    'plans' => [],
                    'error' => $this->catalogError($connection, isEmpty: true),
                ], 422);
            }

            $connection->update(['last_error' => null]);

            return response()->json([
                'success' => true,
                'provider' => $connection->provider,
                'plans' => $plans,
            ]);
        } catch (Throwable $e) {
            $connection->update(['last_error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'plans' => [],
                'error' => $this->catalogError($connection, $e),
            ], 422);
        }
    }

    public function destroyConnection(Request $request, ProviderConnection $connection, TenantContext $context): RedirectResponse
    {
        $this->manage($request);
        $this->guardConnection($connection, $context);

        if ($connection->servers()->exists()) {
            return back()->withErrors(['provider' => 'Remove servers connected to this provider account before deleting its API credentials.']);
        }

        $name = $connection->name;
        $connection->delete();
        ActivityLog::create([
            'tenant_id' => $context->id(),
            'user_id' => $request->user()->id,
            'action' => 'provider.deleted',
            'description' => $name.' provider credentials deleted',
        ]);

        return back()->with('success', $name.' credentials were deleted securely.');
    }

    public function store(Request $request, TenantContext $context, PlanLimitService $limits, ControlPlaneKeyService $keys, CloudProviderFactory $factory): RedirectResponse
    {
        $this->manage($request);
        $limits->enforceFeature($context->current(), 'cloud_api');
        $limits->enforce($context->current(), 'servers');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('servers')->where('tenant_id', $context->id())],
            'provider_connection_id' => ['required', 'integer'],
            'provider_plan_id' => ['required', 'string', 'max:100'],
            'region' => ['required', 'string', 'max:60'],
            'image' => ['required', 'string', 'max:100'],
        ]);

        $connection = $context->current()->providerConnections()
            ->where('platform_managed', false)
            ->where('active', true)
            ->whereNotNull('last_verified_at')
            ->findOrFail($data['provider_connection_id']);

        $plan = $this->tenantCatalogPlan($connection, $data['provider_plan_id'], $factory);

        if (! in_array($data['region'], $plan['regions'] ?? [], true) || ! in_array($data['image'], $plan['images'] ?? [], true)) {
            throw ValidationException::withMessages(['region' => 'The selected region or image is unavailable for this size.']);
        }

        $keyPair = $keys->generate();

        [$server, $operation] = DB::transaction(function () use ($data, $connection, $plan, $context, $request, $keyPair) {
            $server = Server::create([
                'tenant_id' => $context->id(),
                'provider_connection_id' => $connection->id,
                'managed_server_plan_id' => null,
                'name' => $data['name'],
                'provider' => $connection->provider,
                'ip_address' => '0.0.0.0',
                'location' => strtoupper($data['region']),
                'provider_region' => $data['region'],
                'operating_system' => $data['image'],
                'provider_image' => $data['image'],
                'server_type' => 'byos',
                'status' => ServerStatus::Pending,
                'authentication_method' => 'ssh_key',
                'ssh_username' => \App\Services\Infrastructure\ManagedInfrastructureService::PROVISIONING_SSH_USER,
                'cpu_cores' => max(1, (int) ($plan['cpu_cores'] ?? 1)),
                'memory_mb' => max(512, (int) ($plan['memory_mb'] ?? 1024)),
                'disk_gb' => max(10, (int) ($plan['disk_gb'] ?? 10)),
                'install_docker' => true,
                'install_proxy' => true,
                'install_monitoring' => true,
            ]);
            $server->credential()->create(['private_key' => $keyPair['private_key']]);
            foreach ([
                'connect' => 'Connecting to cloud instance',
                'system' => 'Checking system',
                'docker' => 'Installing Docker',
                'configure' => 'Configuring Docker',
                'proxy' => 'Installing reverse proxy',
                'monitoring' => 'Configuring monitoring',
                'verify' => 'Final verification',
            ] as $key => $label) {
                $server->provisioningSteps()->create([
                    'key' => $key,
                    'label' => $label,
                    'position' => $server->provisioningSteps()->count() + 1,
                ]);
            }
            $operation = InfrastructureOperation::create([
                'tenant_id' => $context->id(),
                'server_id' => $server->id,
                'requested_by' => $request->user()->id,
                'action' => 'create',
                'status' => 'pending',
                'parameters' => [
                    'plan' => $plan['provider_plan_id'],
                    'region' => $data['region'],
                    'image' => $data['image'],
                    'public_key' => $keyPair['public_key'],
                    'billing' => false,
                ],
            ]);

            return [$server, $operation];
        });

        CreateManagedServerJob::dispatch($operation->id);

        return redirect()->route('servers.provisioning', $server)
            ->with('success', $server->name.' is queued for automatic provisioning.');
    }

    public function action(Request $request, Server $server, TenantContext $context): RedirectResponse
    {
        abort_unless($server->tenant_id === $context->id() && $server->provider_connection_id, 404);

        $data = $request->validate([
            'action' => ['required', Rule::in(['restart', 'resize', 'rebuild', 'destroy', 'sync'])],
            'managed_server_plan_id' => ['nullable', 'integer'],
            'image' => ['nullable', 'string', 'max:100'],
        ]);

        match ($data['action']) {
            'destroy' => $this->authorize('delete', $server),
            'resize' => $this->authorize('update', $server),
            default => $this->authorize('operate', $server),
        };

        if ($server->infrastructureOperations()->whereIn('status', ['pending', 'running'])->exists()) {
            return back()->withErrors(['operation' => 'Another infrastructure operation is already in progress.']);
        }

        if ($data['action'] === 'resize') {
            ManagedServerPlan::where('provider', $server->provider->value)->findOrFail($data['managed_server_plan_id']);
        }

        if ($data['action'] === 'rebuild' && ! in_array($data['image'], $server->managedPlan->images ?? [], true)) {
            throw ValidationException::withMessages(['image' => 'This image is not available for the server plan.']);
        }

        $operation = InfrastructureOperation::create([
            'tenant_id' => $context->id(),
            'server_id' => $server->id,
            'requested_by' => $request->user()->id,
            'action' => $data['action'],
            'status' => 'pending',
            'parameters' => array_filter([
                'managed_server_plan_id' => $data['managed_server_plan_id'] ?? null,
                'image' => $data['image'] ?? null,
            ]),
        ]);

        ManagedServerActionJob::dispatch($operation->id);

        return back()->with('success', ucfirst($data['action']).' queued.');
    }

    private function manage(Request $request): void
    {
        $role = $request->user()->tenants()->whereKey(session('tenant_id'))->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403);
    }

    private function guardConnection(ProviderConnection $connection, TenantContext $context): void
    {
        abort_unless(
            $connection->tenant_id === $context->id() && ! $connection->platform_managed,
            404
        );
    }

    /**
     * @return array{provider_plan_id: string, name?: string, cpu_cores?: int, memory_mb?: int, disk_gb?: int, regions?: list<string>, images?: list<string>}
     */
    private function tenantCatalogPlan(ProviderConnection $connection, string $providerPlanId, CloudProviderFactory $factory): array
    {
        try {
            $plans = collect($factory->make($connection)->catalog($connection)['plans'] ?? []);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'provider_connection_id' => $this->catalogError($connection, $e),
            ]);
        }

        if ($plans->isEmpty()) {
            throw ValidationException::withMessages([
                'provider_connection_id' => $this->catalogError($connection, isEmpty: true),
            ]);
        }

        $plan = $plans->firstWhere('provider_plan_id', $providerPlanId);
        if (! is_array($plan)) {
            throw ValidationException::withMessages([
                'provider_plan_id' => 'The selected size is not available on this Cloud API account.',
            ]);
        }

        return $plan;
    }

    private function catalogError(ProviderConnection $connection, ?Throwable $exception = null, bool $isEmpty = false): string
    {
        $provider = $connection->provider === 'hetzner' ? 'Hetzner' : 'DigitalOcean';

        if ($exception instanceof RequestException && $exception->response) {
            $status = $exception->response->status();
            if (in_array($status, [401, 403], true)) {
                return $provider.' rejected this API token. Re-verify the connection under My Cloud API.';
            }
        }

        if ($exception) {
            return 'Could not load '.$provider.' sizes for '.$connection->name.': '.$exception->getMessage();
        }

        if ($isEmpty) {
            return 'No provisionable sizes were returned for this '.$provider.' account. Confirm the token can list sizes, regions, and images.';
        }

        return 'Could not load sizes from '.$connection->name.'.';
    }
}
