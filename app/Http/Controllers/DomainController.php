<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDomainRequest;
use App\Jobs\ConfigureDomainJob;
use App\Jobs\IssueCertificateJob;
use App\Jobs\VerifyDomainJob;
use App\Models\ActivityLog;
use App\Models\ApplicationDeployment;
use App\Models\Domain;
use App\Models\Server;
use App\Services\Billing\PlanLimitService;
use App\Services\Networking\DomainNetworkService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DomainController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $tenantId = $context->id();
        $base = Domain::query()->where('tenant_id', $tenantId);

        $stats = [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('status', 'active')->count(),
            'ssl' => (clone $base)->where('dns_status', 'verified')->whereIn('ssl_status', ['valid', 'expiring'])->where('certificate_expires_at', '>', now())->count(),
            'expiring' => (clone $base)->where('dns_status', 'verified')->whereNotNull('certificate_expires_at')->whereBetween('certificate_expires_at', [now(), now()->addDays(30)])->count(),
            'redirects' => (clone $base)->whereNotNull('redirect_to')->count(),
            'attention' => (clone $base)->whereIn('status', ['pending', 'failed'])->count(),
        ];

        $domains = (clone $base)
            ->with([
                'deployment' => fn ($q) => $q->withTrashed(),
                'deployment.application.category',
                'deployment.buildPack',
                'server' => fn ($q) => $q->withTrashed(),
            ])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('hostname', 'like', '%'.$request->string('search').'%')
                ->orWhere('redirect_to', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('ssl'), fn ($q) => $q->where('ssl_status', $request->string('ssl')))
            ->when($request->filled('server'), fn ($q) => $q->where('server_id', $request->integer('server')))
            ->when($request->filled('type'), fn ($q) => $request->string('type')->value() === 'redirect'
                ? $q->whereNotNull('redirect_to')
                : $q->whereNull('redirect_to'))
            ->when($request->sort === 'oldest', fn ($q) => $q->oldest())
            ->when($request->sort === 'name', fn ($q) => $q->orderBy('hostname'))
            ->when($request->sort === 'expiring', fn ($q) => $q->orderBy('certificate_expires_at'))
            ->when(! in_array($request->sort, ['oldest', 'name', 'expiring'], true), fn ($q) => $q->latest())
            ->paginate(12)
            ->withQueryString();

        $deployments = ApplicationDeployment::where('tenant_id', $tenantId)->whereNotNull('container_port')->with('server')->latest()->get();
        $servers = Server::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'status']);

        return view('domains.index', compact('domains', 'stats', 'deployments', 'servers'));
    }

    public function import(Request $request, TenantContext $context, PlanLimitService $limits): RedirectResponse
    {
        $data = $request->validate([
            'application_deployment_id' => ['required', 'integer', 'exists:application_deployments,id'],
            'hostnames' => ['required', 'string', 'max:4000'],
            'ssl_enabled' => ['nullable', 'boolean'],
            'force_https' => ['nullable', 'boolean'],
        ]);

        $deployment = ApplicationDeployment::where('tenant_id', $context->id())->with('server')->findOrFail($data['application_deployment_id']);
        $this->authorize('operate', $deployment->server);

        $pattern = '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';
        $imported = 0;
        $skipped = [];
        $candidates = collect(preg_split('/[\s,;]+/', strtolower($data['hostnames'])) ?: [])
            ->map(fn (string $hostname) => rtrim(trim($hostname), '.'))
            ->filter()
            ->unique()
            ->values();
        $validCount = $candidates->filter(fn (string $hostname) => preg_match($pattern, $hostname) && ! Domain::where('hostname', $hostname)->exists())->count();
        if ($validCount > 0) {
            $limits->enforce($context->current(), 'domains', $validCount);
        }

        foreach ($candidates as $hostname) {
            if (! preg_match($pattern, $hostname) || Domain::where('hostname', $hostname)->exists()) {
                $skipped[] = $hostname;

                continue;
            }

            $domain = Domain::create([
                'tenant_id' => $context->id(),
                'application_deployment_id' => $deployment->id,
                'server_id' => $deployment->server_id,
                'created_by' => $request->user()->id,
                'hostname' => $hostname,
                'force_https' => $request->boolean('force_https', true),
                'ssl_enabled' => $request->boolean('ssl_enabled', true),
                'auto_renew' => true,
                'expected_value' => $deployment->server->ip_address,
                'ssl_status' => $request->boolean('ssl_enabled', true) ? 'pending' : 'disabled',
            ]);

            if (! $deployment->domain) {
                $deployment->update(['domain' => $domain->hostname]);
            }
            VerifyDomainJob::dispatchSync($domain->id, $domain->tenant_id);
            $imported++;
        }

        ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => 'domain.imported', 'description' => $imported.' domain(s) imported for '.$deployment->name, 'subject_type' => ApplicationDeployment::class, 'subject_id' => $deployment->id]);

        $message = $imported.' domain'.($imported === 1 ? '' : 's').' imported. DNS verification has started.';
        if ($skipped) {
            $message .= ' Skipped (invalid or already in use): '.implode(', ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? '…' : '');
        }

        return redirect()->route('domains.index')->with('success', $message);
    }

    public function show(Domain $domain, TenantContext $context): View
    {
        $this->guard($domain, $context);
        $this->authorize('view', $domain->server);

        return view('domains.show', ['domain' => $domain->load(['deployment' => fn ($q) => $q->withTrashed(), 'deployment.buildPack', 'deployment.application', 'server' => fn ($q) => $q->withTrashed()])]);
    }

    public function store(StoreDomainRequest $request, TenantContext $context, PlanLimitService $limits): RedirectResponse
    {
        $limits->enforce($context->current(), 'domains');
        $data = $request->validated();
        $deployment = ApplicationDeployment::where('tenant_id', $context->id())->with('server')->findOrFail($data['application_deployment_id']);
        $this->authorize('operate', $deployment->server);
        $domain = Domain::create([
            'tenant_id' => $context->id(),
            'application_deployment_id' => $deployment->id,
            'server_id' => $deployment->server_id,
            'created_by' => $request->user()->id,
            'hostname' => $data['hostname'],
            'redirect_to' => $data['redirect_to'] ?? null,
            'force_https' => $request->boolean('force_https', true),
            'ssl_enabled' => $request->boolean('ssl_enabled', true),
            'auto_renew' => $request->boolean('auto_renew', true),
            'expected_value' => $deployment->server->ip_address,
            'ssl_status' => $request->boolean('ssl_enabled', true) ? 'pending' : 'disabled',
        ]);
        if (! $deployment->domain) {
            $deployment->update(['domain' => $domain->hostname]);
        }
        ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => 'domain.created', 'description' => $domain->hostname.' added to '.$deployment->name, 'subject_type' => Domain::class, 'subject_id' => $domain->id]);
        VerifyDomainJob::dispatchSync($domain->id, $domain->tenant_id);

        return redirect()->route('domains.show', $domain)->with('success', 'Domain added. DNS verification has started.');
    }

    public function verify(Request $request, Domain $domain, TenantContext $context): RedirectResponse
    {
        $this->operate($domain, $context);
        $domain->update(['dns_status' => 'pending', 'status' => 'verifying', 'failure_reason' => null]);
        // DNS lookup is local — run sync so a down queue worker cannot leave "Verifying" forever.
        // ConfigureDomainJob / IssueCertificateJob stay async on the networking queue.
        VerifyDomainJob::dispatchSync($domain->id, $domain->tenant_id);
        $domain->refresh();

        return back()->with('success', $domain->isDnsVerified()
            ? ($domain->hasValidSsl()
                ? 'DNS verified. Proxy and certificate are active.'
                : ($domain->proxy_status === 'configured'
                    ? 'DNS verified and proxy configured. Certificate is still pending.'
                    : 'DNS verified. Proxy and certificate configuration ran.'))
            : ($domain->failure_reason ?: 'DNS is not pointing to this server yet.'));
    }

    public function configure(Request $request, Domain $domain, TenantContext $context): RedirectResponse
    {
        $this->operate($domain, $context);
        ConfigureDomainJob::dispatchSync($domain->id, $domain->tenant_id);
        $domain->refresh();

        return back()->with('success', $domain->proxy_status === 'configured'
            ? ($domain->hasValidSsl() ? 'Proxy configured and certificate is active.' : 'Proxy configured. Certificate issuance finished or is pending.')
            : ($domain->failure_reason ?: 'Proxy configuration failed.'));
    }

    public function certificate(Request $request, Domain $domain, TenantContext $context): RedirectResponse
    {
        $this->operate($domain, $context);
        abort_unless($domain->isDnsVerified(), 422, 'DNS must be verified before issuing a certificate.');
        $domain->update(['ssl_enabled' => true, 'ssl_status' => 'pending', 'status' => 'verifying', 'failure_reason' => null]);
        IssueCertificateJob::dispatchSync($domain->id, $domain->tenant_id);
        $domain->refresh();

        return back()->with('success', $domain->hasValidSsl()
            ? 'Certificate is active.'
            : ($domain->failure_reason ?: 'Certificate issuance did not complete yet.'));
    }

    public function update(Request $request, Domain $domain, TenantContext $context): RedirectResponse
    {
        $this->operate($domain, $context);
        $data = $request->validate(['redirect_to' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'], 'force_https' => ['nullable', 'boolean'], 'auto_renew' => ['nullable', 'boolean']]);
        $domain->update(['redirect_to' => $data['redirect_to'] ?? null, 'force_https' => $request->boolean('force_https'), 'auto_renew' => $request->boolean('auto_renew')]);
        ConfigureDomainJob::dispatch($domain->id, $domain->tenant_id);

        return back()->with('success', 'Domain routing updated.');
    }

    public function destroy(Request $request, Domain $domain, TenantContext $context, DomainNetworkService $network): RedirectResponse
    {
        $this->operate($domain, $context);
        $network->remove($domain);
        if ($domain->deployment->domain === $domain->hostname) {
            $domain->deployment->update(['domain' => null]);
        }ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => 'domain.removed', 'description' => $domain->hostname.' removed', 'subject_type' => Domain::class, 'subject_id' => $domain->id]);
        $domain->delete();

        return redirect()->route('domains.index')->with('success', 'Domain and proxy route removed.');
    }

    private function operate(Domain $domain, TenantContext $context): void
    {
        $this->guard($domain, $context);
        $this->authorize('operate', $domain->server);
    }

    private function guard(Domain $domain, TenantContext $context): void
    {
        abort_unless($domain->tenant_id === $context->id(),404);
    }
}
