<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PersonalAccessToken;
use App\Services\Billing\PlanLimitService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApiTokenController extends Controller
{
    private const SCOPES = ['servers:read', 'servers:write', 'applications:read', 'applications:write', 'deployments:read', 'deployments:write', 'backups:read', 'backups:write', 'monitoring:read'];

    public function index(Request $request, TenantContext $context): View
    {
        $this->access($request);
        $base = PersonalAccessToken::where('tenant_id', $context->id())->where('tokenable_type', get_class($request->user()))->where('tokenable_id', $request->user()->id);
        $query = (clone $base)
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('environment'), fn ($q) => $q->where('environment', $request->environment));
        if ($request->status === 'active') {
            $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }
        if ($request->status === 'expired') {
            $query->whereNull('revoked_at')->where('expires_at', '<=', now());
        }
        if ($request->status === 'revoked') {
            $query->whereNotNull('revoked_at');
        }
        if ($request->filled('permission')) {
            $query->whereJsonContains('abilities', $request->permission);
        }
        $tokens = $query->latest()->paginate(8)->withQueryString();
        $selected = $request->filled('selected') ? (clone $base)->find($request->integer('selected')) : $tokens->first();
        $all = $base->get();

        return view('commercial.api-tokens', compact('tokens', 'selected') + [
            'scopes' => self::SCOPES, 'plainToken' => session('plain_api_token'),
            'stats' => ['total' => $all->count(), 'active' => $all->where('status', 'active')->count(), 'expired' => $all->where('status', 'expired')->count(), 'revoked' => $all->where('status', 'revoked')->count()],
        ]);
    }

    public function store(Request $request, TenantContext $context): RedirectResponse
    {
        $this->access($request);
        app(PlanLimitService::class)->enforceFeature($context->current(), 'api_tokens');
        app(PlanLimitService::class)->enforce($context->current(), 'api_tokens');
        $data = $this->validated($request);
        $created = $request->user()->createToken($data['name'], $data['scopes'], $this->expiry($data['expires_in']));
        $created->accessToken->update(['tenant_id' => $context->id(), 'environment' => $data['environment'], 'ip_restrictions' => $this->ips($data['ip_restrictions'] ?? '') ?: null]);
        $this->log($request, $context, 'token.created', $data['name'].' API token created', $data['scopes']);

        return redirect()->route('api-tokens.index', ['selected' => $created->accessToken->id])->with('success', 'API token created. Copy it now; it will not be shown again.')->with('plain_api_token', $created->plainTextToken);
    }

    public function update(Request $request, PersonalAccessToken $token, TenantContext $context): RedirectResponse
    {
        $this->access($request);
        $this->guard($request, $token, $context);
        $data = $this->validated($request);
        $token->update(['name' => $data['name'], 'abilities' => $data['scopes'], 'environment' => $data['environment'], 'expires_at' => $this->expiry($data['expires_in']), 'ip_restrictions' => $this->ips($data['ip_restrictions'] ?? '') ?: null]);
        $this->log($request, $context, 'token.updated', $data['name'].' API token updated', $data['scopes']);

        return redirect()->route('api-tokens.index', ['selected' => $token->id])->with('success', 'API token updated.');
    }

    public function destroy(Request $request, PersonalAccessToken $token, TenantContext $context): RedirectResponse
    {
        $this->access($request);
        $this->guard($request, $token, $context);
        $token->update(['revoked_at' => now()]);
        $this->log($request, $context, 'token.revoked', $token->name.' API token revoked');

        return redirect()->route('api-tokens.index')->with('success', 'API token revoked.');
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:100'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => [Rule::in(self::SCOPES)], 'environment' => ['required', 'in:production,staging,development'], 'expires_in' => ['required', 'in:30,90,365,never'], 'ip_restrictions' => ['nullable', 'string', 'max:1000']]);
    }

    private function expiry(string $value)
    {
        return $value === 'never' ? null : now()->addDays((int) $value);
    }

    private function ips(string $value): array
    {
        return collect(preg_split('/[\s,]+/', $value))->filter()->each(fn ($ip) => abort_unless(filter_var($ip, FILTER_VALIDATE_IP), 422))->values()->all();
    }

    private function guard(Request $request, PersonalAccessToken $token, TenantContext $context): void
    {
        abort_unless($token->tenant_id === $context->id() && $token->tokenable_id === $request->user()->id, 404);
    }

    private function access(Request $request): void
    {
        $role = $request->user()->tenants()->whereKey(session('tenant_id'))->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin', 'developer'], true), 403);
    }

    private function log(Request $request, TenantContext $context, string $action, string $description, array $scopes = []): void
    {
        ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => $action, 'description' => $description, 'ip_address' => $request->ip(), 'status' => 'success', 'metadata' => ['scopes' => $scopes]]);
    }
}
