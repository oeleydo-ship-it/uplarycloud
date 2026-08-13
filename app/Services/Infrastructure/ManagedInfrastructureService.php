<?php

namespace App\Services\Infrastructure;

use App\Enums\ServerStatus;
use App\Events\ManagedInfrastructureUpdated;
use App\Models\ActivityLog;
use App\Models\InfrastructureOperation;
use App\Models\ManagedServerPlan;
use App\Models\Server;
use RuntimeException;

class ManagedInfrastructureService
{
    public function __construct(private readonly CloudProviderFactory $providers, private readonly InfrastructureBillingService $billing) {}

    public function create(InfrastructureOperation $operation): void
    {
        $operation->loadMissing('server.providerConnection');
        $server = $operation->server;
        $plan = $server->managedPlan ?? throw new RuntimeException('Managed plan is missing.');
        $adapter = $this->providers->make($server->providerConnection);
        $this->start($operation, 'Requesting a new '.$plan->name.' instance from '.$server->provider->label().'.');
        $parameters = $operation->parameters ?? [];
        $result = $adapter->create($server, $plan, ['region' => $server->provider_region, 'image' => $server->provider_image, 'user_data' => $this->cloudInit($parameters['public_key'] ?? null)]);
        $server->update(['provider_resource_id' => $result['resource_id'], 'ip_address' => $result['ip_address'], 'status' => ServerStatus::Provisioning, 'provider_created_at' => now(), 'failure_reason' => null]);
        if ($server->ip_address === '0.0.0.0') {
            for ($attempt = 0; $attempt < 15; $attempt++) {
                sleep(2);
                $result = $adapter->status($server);
                if (($result['ip_address'] ?? '0.0.0.0') !== '0.0.0.0') {
                    $server->update(['ip_address' => $result['ip_address']]);
                    break;
                }
            }
        }if ($server->fresh()->ip_address === '0.0.0.0') {
            throw new RuntimeException('The cloud provider did not assign a public IP address in time.');
        }if ($server->isManaged() && ($parameters['billing'] ?? true)) {
            $this->billing->accrue($server->load('managedPlan'), $operation);
        }$this->complete($operation, $result, 'Cloud instance created. SSH and Docker provisioning are queued.');
        ActivityLog::create(['tenant_id' => $server->tenant_id, 'user_id' => $operation->requested_by, 'action' => 'managed-server.created', 'description' => $server->name.' created on '.$server->provider->label(), 'subject_type' => Server::class, 'subject_id' => $server->id]);
    }

    public function perform(InfrastructureOperation $operation): void
    {
        $operation->loadMissing('server.providerConnection');
        $server = $operation->server;
        $adapter = $this->providers->make($server->providerConnection);
        $parameters = $operation->parameters ?? [];
        $this->start($operation, ucfirst($operation->action).' requested from '.$server->provider->label().'.');
        $result = match ($operation->action) {
            'restart' => $adapter->restart($server),'resize' => $this->resize($adapter, $server, $operation, $parameters),'rebuild' => $adapter->rebuild($server, $parameters['image'] ?? $server->provider_image),'destroy' => $adapter->destroy($server),'sync' => $adapter->status($server),default => throw new RuntimeException('Unsupported infrastructure action.')
        };
        if (isset($result['ip_address'])) {
            $server->update(['ip_address' => $result['ip_address']]);
        }if ($operation->action === 'rebuild') {
            $server->update(['provider_image' => $parameters['image'] ?? $server->provider_image, 'operating_system' => $parameters['image'] ?? $server->operating_system, 'status' => ServerStatus::Provisioning]);
        } elseif ($operation->action === 'destroy') {
            $server->update(['status' => ServerStatus::Offline]);
        } elseif (in_array($operation->action, ['restart', 'sync'], true)) {
            $server->update(['status' => ServerStatus::Online, 'last_seen_at' => now()]);
        }$this->complete($operation, $result, ucfirst($operation->action).' completed successfully.');
        ActivityLog::create(['tenant_id' => $server->tenant_id, 'user_id' => $operation->requested_by, 'action' => 'managed-server.'.$operation->action, 'description' => $server->name.' '.$operation->action.' completed', 'subject_type' => Server::class, 'subject_id' => $server->id]);
    }

    public function fail(InfrastructureOperation $operation, \Throwable $exception): void
    {
        $operation->update(['status' => 'failed', 'last_error' => $exception->getMessage(), 'completed_at' => now()]);
        $operation->server()->update(['status' => ServerStatus::Failed, 'failure_reason' => 'Managed infrastructure operation failed.']);
        event(new ManagedInfrastructureUpdated($operation->fresh()));
    }

    private function resize($adapter, Server $server, InfrastructureOperation $operation, array $parameters): array
    {
        $plan = ManagedServerPlan::where('provider', $server->provider->value)->findOrFail($parameters['managed_server_plan_id'] ?? null);
        $oldPrice = $server->managedPlan->monthly_price;
        $result = $adapter->resize($server, $plan);
        $server->update(['managed_server_plan_id' => $plan->id, 'cpu_cores' => $plan->cpu_cores, 'memory_mb' => $plan->memory_mb, 'disk_gb' => $plan->disk_gb]);
        $server->load('managedPlan');
        if ($server->isManaged()) {
            $this->billing->resizeAdjustment($server, $oldPrice, $operation);
        }

return $result;
    }

    private function start(InfrastructureOperation $operation, string $message): void
    {
        $operation->update(['status' => 'running', 'started_at' => now(), 'log' => $message, 'last_error' => null]);
        event(new ManagedInfrastructureUpdated($operation->fresh()));
    }

    private function complete(InfrastructureOperation $operation, array $response, string $message): void
    {
        $operation->update(['status' => 'completed', 'provider_response' => $response, 'log' => $message, 'completed_at' => now()]);
        event(new ManagedInfrastructureUpdated($operation->fresh()));
    }

    private function cloudInit(?string $publicKey): string
    {
        $key = $publicKey ? str_replace(["\r", "\n"], '', trim($publicKey)) : null;

        return "#cloud-config\n"
            ."package_update: true\n"
            ."packages: [ca-certificates, curl]\n"
            ."ssh_pwauth: false\n"
            ."disable_root: false\n"
            .($key ? "users:\n  - name: root\n    lock_passwd: true\n    ssh_authorized_keys:\n      - {$key}\n" : '')
            ."runcmd:\n  - mkdir -p /opt/uplary\n";
    }
}
