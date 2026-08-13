<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Services\Infrastructure\CloudProviderFactory;
use App\Services\Infrastructure\ManagedServerPlanSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class CloudInfrastructureController extends Controller
{
    public function index(ManagedServerPlanSyncService $sync): View
    {
        return view('admin.cloud', [
            'connections' => ProviderConnection::where('platform_managed', true)->latest()->get(),
            'plans' => ManagedServerPlan::orderBy('provider')->orderBy('position')->orderBy('monthly_cost')->get(),
            'globalMarkup' => $sync->globalMarkup(),
        ]);
    }

    public function storeConnection(Request $request, CloudProviderFactory $factory, ManagedServerPlanSyncService $sync): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'provider' => ['required', Rule::in(['digitalocean', 'hetzner'])],
            'api_token' => 'required|string|max:2000',
            'account_id' => 'nullable|string|max:255',
            'active' => 'nullable|boolean',
        ]);

        $connection = ProviderConnection::create($data + [
            'tenant_id' => null,
            'platform_managed' => true,
            'active' => $request->boolean('active'),
        ]);

        return $this->check($connection, $factory, $sync, 'Cloud connection saved, verified, and plans synced.');
    }

    public function updateConnection(Request $request, ProviderConnection $c, CloudProviderFactory $factory, ManagedServerPlanSyncService $sync): RedirectResponse
    {
        abort_unless($c->platform_managed, 404);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'api_token' => 'nullable|string|max:2000',
            'account_id' => 'nullable|string|max:255',
            'active' => 'nullable|boolean',
        ]);

        if (blank($data['api_token'] ?? null)) {
            unset($data['api_token']);
        }

        $c->update($data + ['active' => $request->boolean('active')]);

        return isset($data['api_token'])
            ? $this->check($c, $factory, $sync, 'Cloud connection updated, verified, and plans synced.')
            : back()->with('success', 'Cloud connection updated.');
    }

    public function verify(ProviderConnection $c, CloudProviderFactory $factory, ManagedServerPlanSyncService $sync): RedirectResponse
    {
        abort_unless($c->platform_managed, 404);

        return $this->check($c, $factory, $sync, 'Cloud API verified and customer plans synced.');
    }

    public function syncPlans(ProviderConnection $c, ManagedServerPlanSyncService $sync): RedirectResponse
    {
        abort_unless($c->platform_managed && $c->last_verified_at, 404);

        try {
            $result = $sync->syncConnection($c);

            return back()->with('success', "Synced {$result['synced']} plans ({$result['created']} new, {$result['updated']} updated).");
        } catch (Throwable $e) {
            return back()->withErrors(['provider' => $e->getMessage()]);
        }
    }

    public function syncAllPlans(ManagedServerPlanSyncService $sync): RedirectResponse
    {
        try {
            $result = $sync->syncAll();

            return back()->with('success', "Synced {$result['synced']} plans from {$result['connections']} provider connection(s).");
        } catch (Throwable $e) {
            return back()->withErrors(['provider' => $e->getMessage()]);
        }
    }

    public function storePlan(Request $request, ManagedServerPlanSyncService $sync): RedirectResponse
    {
        ManagedServerPlan::create($this->planData($request, null, $sync));

        return back()->with('success', 'Managed server plan created.');
    }

    public function updatePlan(Request $request, ManagedServerPlan $p, ManagedServerPlanSyncService $sync): RedirectResponse
    {
        $p->update($this->planData($request, $p, $sync));

        return back()->with('success', 'Managed server plan updated.');
    }

    public function updateGlobalMarkup(Request $request, ManagedServerPlanSyncService $sync): RedirectResponse
    {
        $data = $request->validate(['markup_percentage' => 'required|numeric|min:0|max:1000']);
        $markup = (int) round($data['markup_percentage']);
        $count = $sync->applyMarkupToActivePlans($markup);

        return back()->with('success', $markup.'% markup applied to '.$count.' published managed server plan(s).');
    }

    private function planData(Request $request, ?ManagedServerPlan $plan, ManagedServerPlanSyncService $sync): array
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['digitalocean', 'hetzner'])],
            'provider_plan_id' => ['required', 'string', 'max:100', Rule::unique('managed_server_plans')->where('provider', $request->provider)->ignore($plan)],
            'name' => 'required|string|max:100',
            'cpu_cores' => 'required|integer|min:1',
            'memory_mb' => 'required|integer|min:512',
            'disk_gb' => 'required|integer|min:10',
            'bandwidth_gb' => 'nullable|integer|min:0',
            'monthly_cost' => 'required|numeric|min:0',
            'markup_percentage' => 'required|numeric|min:0|max:1000',
            'currency' => 'required|string|size:3',
            'regions' => 'required|string',
            'images' => 'required|string',
            'featured' => 'nullable|boolean',
            'active' => 'nullable|boolean',
        ]);

        $cost = (int) round($data['monthly_cost'] * 100);
        $markup = (int) round($data['markup_percentage']);
        $data['monthly_cost'] = $cost;
        $data['markup_percentage'] = $markup;
        $data['monthly_price'] = $sync->priceFromCost($cost, $markup);
        $data['regions'] = $this->csv($data['regions']);
        $data['images'] = $this->csv($data['images']);
        $data['featured'] = $request->boolean('featured');
        $data['active'] = $request->boolean('active');
        $data['position'] = $plan?->position ?? ((int) ManagedServerPlan::max('position') + 1);

        return $data;
    }

    private function csv(string $value): array
    {
        return collect(explode(',', $value))->map(fn ($item) => trim($item))->filter()->values()->all();
    }

    private function check(ProviderConnection $connection, CloudProviderFactory $factory, ManagedServerPlanSyncService $sync, string $message): RedirectResponse
    {
        try {
            $factory->make($connection)->verify($connection);
            $connection->update(['last_verified_at' => now(), 'last_error' => null]);
            $result = $sync->syncConnection($connection);

            return back()->with('success', $message.' '.$result['synced'].' size(s) published.');
        } catch (Throwable $e) {
            $connection->update([
                'last_verified_at' => null,
                'last_error' => $e->getMessage(),
                'active' => false,
            ]);

            return back()->withErrors(['provider' => $e->getMessage()]);
        }
    }
}
