<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use App\Jobs\ProvisionServerJob;
use App\Enums\ServerStatus;
use App\Services\Servers\ServerProvisionVerifier;

class ServerProvisioningController extends Controller
{
    public function show(Server $server, TenantContext $context, ServerProvisionVerifier $verifier): View
    {
        $this->guard($server, $context);

        return view('servers.provisioning', [
            'server' => $server->load('provisioningSteps'),
            'needsAttention' => $this->needsAttention($server, $verifier),
            'attentionMessage' => $this->attentionMessage($server, $verifier),
        ]);
    }

    public function status(Server $server, TenantContext $context): JsonResponse
    {
        $this->guard($server, $context);
        $server->refresh()->load('provisioningSteps');

        return response()->json([
            'status' => $server->status->value,
            'steps' => $server->provisioningSteps,
            'redirect' => $server->status === ServerStatus::Online && $server->isFullyProvisioned()
                ? route('servers.success', $server)
                : null,
        ]);
    }

    public function success(Server $server, TenantContext $context): View
    {
        $this->guard($server, $context);
        abort_unless($server->status === ServerStatus::Online && $server->isFullyProvisioned(), 409);

        return view('servers.success', compact('server'));
    }

    public function retry(Server $server, TenantContext $context, ServerProvisionVerifier $verifier): RedirectResponse
    {
        $this->guard($server, $context);
        abort_unless($this->needsAttention($server, $verifier), 409);

        $server->provisioningSteps()->update([
            'status' => 'pending',
            'message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
        $server->update([
            'status' => ServerStatus::Pending,
            'failure_reason' => null,
            'provisioned_at' => null,
            'docker_version' => null,
            'docker_compose_version' => null,
            'proxy_status' => 'not_installed',
            'proxy_version' => null,
            'proxy_installed_at' => null,
            'last_seen_at' => null,
        ]);
        ProvisionServerJob::dispatch($server, force: true);

        return redirect()
            ->route('servers.provisioning', $server)
            ->with('success', 'Provisioning queued.');
    }

    private function needsAttention(Server $server, ServerProvisionVerifier $verifier): bool
    {
        if (in_array($server->status, [ServerStatus::Failed, ServerStatus::Pending], true)) {
            return true;
        }

        if ($server->isProvisioningIncomplete()) {
            return true;
        }

        if ($server->status === ServerStatus::Online && config('infrastructure.driver') === 'ssh') {
            return $verifier->failures($server) !== [];
        }

        return false;
    }

    private function attentionMessage(Server $server, ServerProvisionVerifier $verifier): ?string
    {
        if ($server->failure_reason) {
            return $server->failure_reason;
        }

        if ($server->status === ServerStatus::Pending) {
            return 'Queue the installation job to begin Docker and platform setup. Ensure a worker is listening on the provisioning queue.';
        }

        $failures = $verifier->failures($server);
        if ($failures !== []) {
            return implode(' ', $failures).' Re-run provisioning with INFRASTRUCTURE_DRIVER=ssh and a queue worker processing the provisioning queue.';
        }

        return null;
    }

    private function guard(Server $server, TenantContext $context): void
    {
        abort_unless($server->tenant_id === $context->id(), 404);
        $this->authorize('view', $server);
    }
}
