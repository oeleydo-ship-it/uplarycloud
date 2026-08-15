<?php

namespace App\Http\Controllers;

use App\Models\InfrastructureOperation;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;
use App\Services\Billing\ManagedServerCheckoutService;
use App\Services\Billing\PlanLimitService;
use App\Support\PlatformSettings;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

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

    public function store(Request $request, TenantContext $context, PlanLimitService $limits, PlatformSettings $settings, ManagedServerCheckoutService $checkout): RedirectResponse
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

        try {
            $result = $checkout->begin(
                $context->current(),
                $request->user(),
                $data,
                route('managed.index', ['checkout' => 'success']),
                route('managed.index', ['checkout' => 'canceled']),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'payment' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to start managed server payment. Try again or contact support.',
            ]);
        }

        if ($result['redirect']) {
            return redirect()->away($result['redirect']);
        }

        $server = $result['server'];

        return redirect()->route('servers.provisioning', $server)
            ->with('success', $server->name.' is being created and provisioned.');
    }

    private function manage(Request $request): void
    {
        $role = $request->user()->tenants()->whereKey(session('tenant_id'))->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403);
    }
}
