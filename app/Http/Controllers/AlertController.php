<?php

namespace App\Http\Controllers;

use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\DockerContainer;
use App\Models\Server;
use App\Services\Billing\PlanLimitService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved'])],
            'severity' => ['nullable', Rule::in(['warning', 'error', 'critical'])],
            'server_id' => ['nullable', 'exists:servers,id'],
        ]);

        $incidentsQuery = AlertIncident::where('tenant_id', $context->id())
            ->with('rule')
            ->latest('triggered_at');

        if (! empty($filters['status'])) {
            $incidentsQuery->where('status', $filters['status']);
        }
        if (! empty($filters['severity'])) {
            $incidentsQuery->where('severity', $filters['severity']);
        }
        if (! empty($filters['server_id'])) {
            $incidentsQuery->whereHas('rule', fn ($q) => $q->where('server_id', $filters['server_id']));
        }

        $incidents = $incidentsQuery->paginate(12)->appends($request->query());

        return view('operations.alerts', [
            'rules' => AlertRule::where('tenant_id', $context->id())->with(['server' => fn ($q) => $q->withTrashed(), 'container' => fn ($q) => $q->withTrashed(), 'incidents' => fn ($q) => $q->latest('triggered_at')->limit(1)])->latest()->get(),
            'incidents' => $incidents,
            'servers' => Server::where('tenant_id', $context->id())->get(),
            'containers' => DockerContainer::where('tenant_id', $context->id())->get(),
        ]);
    }

    public function store(Request $request, TenantContext $context, PlanLimitService $limits): RedirectResponse
    {
        $limits->enforceFeature($context->current(), 'alerts');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'type' => ['required', Rule::in(['server_offline', 'cpu_high', 'memory_high', 'disk_high', 'container_down', 'container_restarting', 'health_failed', 'deployment_failed', 'backup_failed', 'ssl_expiring'])], 'server_id' => ['nullable', 'exists:servers,id'], 'docker_container_id' => ['nullable', 'exists:docker_containers,id'], 'threshold' => ['nullable', 'numeric', 'between:0,100000'], 'duration_minutes' => ['required', 'integer', 'between:1,1440'], 'severity' => ['required', 'in:warning,error,critical']]);
        $server = ! empty($data['server_id']) ? Server::where('tenant_id', $context->id())->findOrFail($data['server_id']) : Server::where('tenant_id', $context->id())->firstOrFail();
        $this->authorize('operate', $server);
        if (! empty($data['docker_container_id'])) {
            DockerContainer::where('tenant_id', $context->id())->findOrFail($data['docker_container_id']);
        }AlertRule::create($data + ['tenant_id' => $context->id(), 'metric' => str_replace(['_high', '_expiring'], '', $data['type']), 'operator' => '>=', 'channels' => ['dashboard']]);

        return back()->with('success', 'Alert rule created.');
    }

    public function toggle(AlertRule $alert, TenantContext $context): RedirectResponse
    {
        $this->guardRule($alert, $context);
        $server = $alert->server ?? Server::where('tenant_id', $context->id())->firstOrFail();
        $this->authorize('operate', $server);
        $alert->update(['enabled' => ! $alert->enabled]);

        return back()->with('success', 'Alert rule updated.');
    }

    public function acknowledge(AlertIncident $incident, TenantContext $context): RedirectResponse
    {
        abort_unless($incident->tenant_id === $context->id(), 404);
        $server = $incident->rule->server ?? Server::where('tenant_id', $context->id())->firstOrFail();
        $this->authorize('operate', $server);
        $incident->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);

        return back()->with('success', 'Incident acknowledged.');
    }

    public function resolve(AlertIncident $incident, TenantContext $context): RedirectResponse
    {
        abort_unless($incident->tenant_id === $context->id(), 404);
        $server = $incident->rule->server ?? Server::where('tenant_id', $context->id())->firstOrFail();
        $this->authorize('operate', $server);
        $incident->update(['status' => 'resolved', 'resolved_at' => now()]);

        return back()->with('success', 'Incident resolved.');
    }

    private function guardRule(AlertRule $rule,TenantContext $context): void
    {
        abort_unless($rule->tenant_id === $context->id(),404);
    }
}
