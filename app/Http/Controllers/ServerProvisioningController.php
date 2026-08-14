<?php

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Jobs\CreateManagedServerJob;
use App\Jobs\ProvisionServerJob;
use App\Models\InfrastructureOperation;
use App\Models\Server;
use App\Services\Servers\ServerProvisionVerifier;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redis;
use Illuminate\View\View;
use Throwable;

class ServerProvisioningController extends Controller
{
    public function show(Server $server, TenantContext $context, ServerProvisionVerifier $verifier): View
    {
        $this->guard($server, $context);
        $this->recoverStuckProvisioning($server);
        $server->refresh()->load('provisioningSteps');

        return view('servers.provisioning', [
            'server' => $server,
            'needsAttention' => $this->needsAttention($server, $verifier),
            'attentionMessage' => $this->attentionMessage($server, $verifier),
        ]);
    }

    public function status(Server $server, TenantContext $context, ServerProvisionVerifier $verifier): JsonResponse
    {
        $this->guard($server, $context);
        $this->recoverStuckProvisioning($server);
        $server->refresh()->load('provisioningSteps');

        return response()->json([
            'status' => $server->status->value,
            'steps' => $server->provisioningSteps,
            'failure_reason' => $server->failure_reason,
            'needs_attention' => $this->needsAttention($server, $verifier),
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

        if ($this->cloudCreatePending($server)) {
            CreateManagedServerJob::dispatch($this->pendingCreateOperation($server)->id);

            return redirect()
                ->route('servers.provisioning', $server)
                ->with('success', 'Cloud instance creation queued. Docker setup starts after the droplet has a public IP.');
        }

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
        ProvisionServerJob::dispatch($server->fresh(), force: true);

        return redirect()
            ->route('servers.provisioning', $server)
            ->with('success', 'Provisioning queued.');
    }

    private function recoverStuckProvisioning(Server $server): void
    {
        $create = $this->pendingCreateOperation($server);
        if ($create && $this->cloudCreatePending($server)) {
            if (! $this->queueMentions('infrastructure', ['operationId";i:'.$create->id.';'])) {
                CreateManagedServerJob::dispatch($create->id);
            }

            return;
        }

        if (! in_array($server->status, [ServerStatus::Pending, ServerStatus::Provisioning], true)) {
            return;
        }

        if ($server->ip_address === '0.0.0.0' || $server->ip_address === '') {
            return;
        }

        $steps = $server->relationLoaded('provisioningSteps')
            ? $server->provisioningSteps
            : $server->provisioningSteps()->get();

        if ($steps->isEmpty() || $steps->contains(fn ($step) => $step->status !== 'pending')) {
            return;
        }

        $oldest = $server->updated_at ?? $server->created_at;
        if ($oldest && $oldest->gt(now()->subSeconds(20))) {
            return;
        }

        if ($this->queueMentions('provisioning', [$server->uuid])) {
            return;
        }

        ProvisionServerJob::dispatch($server);
    }

    /**
     * @param  list<string>  $needles
     */
    private function queueMentions(string $queue, array $needles): bool
    {
        if (config('queue.default') !== 'redis') {
            return false;
        }

        try {
            $name = (string) config('infrastructure.queues.'.$queue, $queue);
            $payloads = array_merge(
                Redis::lrange('queues:'.$name, 0, -1) ?: [],
                Redis::zrange('queues:'.$name.':delayed', 0, -1) ?: [],
            );
            foreach ($payloads as $payload) {
                $haystack = (string) $payload;
                $decoded = json_decode($haystack, true);
                $command = is_array($decoded) ? (string) ($decoded['data']['command'] ?? '') : '';
                foreach ($needles as $needle) {
                    if ($needle !== '' && (str_contains($haystack, $needle) || str_contains($command, $needle))) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    private function cloudCreatePending(Server $server): bool
    {
        if ($this->pendingCreateOperation($server) === null) {
            return false;
        }

        return $server->ip_address === '0.0.0.0' || $server->ip_address === '' || blank($server->provider_resource_id);
    }

    private function pendingCreateOperation(Server $server): ?InfrastructureOperation
    {
        return $server->infrastructureOperations()
            ->where('action', 'create')
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();
    }

    private function needsAttention(Server $server, ServerProvisionVerifier $verifier): bool
    {
        // Action needed only when the user must start/retry — not while provisioning is actively running.
        if (in_array($server->status, [ServerStatus::Failed, ServerStatus::Pending], true)) {
            return true;
        }

        if ($server->status === ServerStatus::Online && ! $server->isFullyProvisioned()) {
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

        if ($this->cloudCreatePending($server)) {
            return 'The cloud instance is still being created. Keep a worker on the infrastructure and provisioning queues; Docker setup starts after the droplet has a public IP.';
        }

        if ($server->status === ServerStatus::Pending) {
            return 'Queue the installation job to begin Docker and platform setup. Ensure a worker is listening on the provisioning queue.';
        }

        // Never perform SSH health checks while rendering an active provisioning page.
        // The queued provisioner owns those checks; doing them here can block the web
        // request for multiple connection timeouts when the new host is not ready yet.
        if ($server->status !== ServerStatus::Online) {
            return null;
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
