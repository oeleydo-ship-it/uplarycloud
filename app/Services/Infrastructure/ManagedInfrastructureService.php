<?php

namespace App\Services\Infrastructure;

use App\Enums\ServerStatus;
use App\Events\ManagedInfrastructureUpdated;
use App\Jobs\ServerRecoveryJob;
use App\Models\ActivityLog;
use App\Models\InfrastructureOperation;
use App\Models\ManagedServerPlan;
use App\Models\Server;
use App\Services\Servers\ServerMaintenanceService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ManagedInfrastructureService
{
    public const PROVISIONING_SSH_USER = 'uplary';

    public function __construct(
        private readonly CloudProviderFactory $providers,
        private readonly InfrastructureBillingService $billing,
        private readonly ServerMaintenanceService $maintenance,
    ) {}

    public function create(InfrastructureOperation $operation): void
    {
        $operation->loadMissing('server.providerConnection', 'server.managedPlan');
        $server = $operation->server;
        $parameters = $operation->parameters ?? [];

        if ($operation->status === 'completed' && $server->ip_address !== '0.0.0.0' && filled($server->provider_resource_id)) {
            return;
        }

        $plan = $this->planFor($server, $parameters);
        $adapter = $this->providers->make($server->providerConnection);
        $this->start($operation, 'Requesting a new '.$plan->name.' managed server.');

        // A provider ID means the remote server already exists. A previous attempt may
        // have timed out while waiting for its public IP; retrying must poll that same
        // resource instead of charging the customer for another cloud server.
        if (filled($server->provider_resource_id)) {
            $result = $adapter->status($server);
            $server->update([
                'ip_address' => $result['ip_address'] ?? $server->ip_address,
                'status' => ServerStatus::Provisioning,
                'failure_reason' => null,
            ]);
        } else {
            $result = $adapter->create($server, $plan, [
                'region' => $server->provider_region,
                'image' => $server->provider_image,
                'user_data' => $this->cloudInit($parameters['public_key'] ?? null),
            ]);
            $server->update([
                'provider_resource_id' => $result['resource_id'],
                'ip_address' => $result['ip_address'],
                'status' => ServerStatus::Provisioning,
                'provider_created_at' => now(),
                'failure_reason' => null,
            ]);
        }
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
            throw new RuntimeException('The managed server did not receive a public IP address in time.');
        }if ($server->isManaged() && ($parameters['billing'] ?? true)) {
            $this->billing->accrue($server->load('managedPlan'), $operation);
        }$this->complete($operation, $result, 'Managed server created. SSH and Docker provisioning are queued.');
        ActivityLog::create(['tenant_id' => $server->tenant_id, 'user_id' => $operation->requested_by, 'action' => 'managed-server.created', 'description' => $server->name.' managed server created', 'subject_type' => Server::class, 'subject_id' => $server->id]);
    }

    public function perform(InfrastructureOperation $operation): void
    {
        $operation->loadMissing('server.providerConnection');
        $server = $operation->server;
        $adapter = $this->providers->make($server->providerConnection);
        $parameters = $operation->parameters ?? [];
        $this->start($operation, ucfirst($operation->action).' requested for the managed server.');
        if ($operation->action === 'restart') {
            $server->update(['status' => ServerStatus::Maintenance, 'failure_reason' => null]);
            try {
                $this->maintenance->enable($server);
            } catch (Throwable $exception) {
                Log::warning('Could not enable visitor maintenance page before managed restart', [
                    'server_id' => $server->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
        $result = match ($operation->action) {
            'restart' => $adapter->restart($server),
            'poweroff' => $adapter->powerOff($server),
            'resize' => $this->resize($adapter, $server, $operation, $parameters),
            'rebuild' => $adapter->rebuild($server, $parameters['image'] ?? $server->provider_image),
            'destroy' => $adapter->destroy($server),
            'sync' => $adapter->status($server),
            default => throw new RuntimeException('Unsupported infrastructure action.'),
        };
        if (isset($result['ip_address'])) {
            $server->update(['ip_address' => $result['ip_address']]);
        }
        if ($operation->action === 'rebuild') {
            $server->update(['provider_image' => $parameters['image'] ?? $server->provider_image, 'operating_system' => $parameters['image'] ?? $server->operating_system, 'status' => ServerStatus::Provisioning]);
        } elseif ($operation->action === 'destroy' || $operation->action === 'poweroff') {
            $server->update(['status' => ServerStatus::Offline, 'last_seen_at' => now()]);
        } elseif (in_array($operation->action, ['restart', 'sync'], true)) {
            if ($operation->action === 'restart') {
                ServerRecoveryJob::dispatch($server->id)->delay(now()->addSeconds(60));
            } else {
                $server->update(['status' => ServerStatus::Online, 'last_seen_at' => now()]);
            }
        }
        $this->complete($operation, $result, ucfirst($operation->action).' completed successfully.');
        ActivityLog::create(['tenant_id' => $server->tenant_id, 'user_id' => $operation->requested_by, 'action' => 'managed-server.'.$operation->action, 'description' => $server->name.' '.$operation->action.' completed', 'subject_type' => Server::class, 'subject_id' => $server->id]);
    }

    public function destroyByoCloud(Server $server, bool $force = false): array
    {
        if (! $server->isByoCloud()) {
            throw new RuntimeException('This server is not linked to a customer cloud API resource.');
        }

        $server->loadMissing('providerConnection');
        $connection = $server->providerConnection;
        if (! $connection || $connection->tenant_id !== $server->tenant_id || $connection->platform_managed) {
            throw new RuntimeException('The customer cloud API connection is missing or invalid.');
        }

        $adapter = $this->providers->make($connection);

        return $force
            ? $adapter->destroy($server)
            : $adapter->destroyWithAssociatedResources($server);
    }

    public function byoCloudResourceDeleted(Server $server): bool
    {
        if (! $server->isByoCloud() || blank($server->provider_resource_id)) {
            return false;
        }

        $server->loadMissing('providerConnection');
        $connection = $server->providerConnection;
        if (! $connection || $connection->tenant_id !== $server->tenant_id || $connection->platform_managed) {
            return false;
        }

        try {
            $result = $this->providers->make($connection)->status($server);

            return in_array($result['status'] ?? '', ['deleted', 'not_found', 'missing'], true);
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());

            return str_contains($message, 'not found')
                || str_contains($message, '404')
                || str_contains($message, 'does not exist');
        }
    }

    public function fail(InfrastructureOperation $operation, \Throwable $exception): void
    {
        $server = $operation->server()->first();
        if ($server && filled($server->provider_resource_id) && $server->ip_address !== '0.0.0.0') {
            $operation->update(['status' => 'completed', 'last_error' => null, 'completed_at' => $operation->completed_at ?? now()]);

            return;
        }

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

    private function planFor(Server $server, array $parameters): ManagedServerPlan
    {
        if ($server->managedPlan) {
            return $server->managedPlan;
        }

        $providerPlanId = (string) ($parameters['plan'] ?? '');
        if ($providerPlanId === '') {
            throw new RuntimeException('Managed plan is missing.');
        }

        return new ManagedServerPlan([
            'provider_plan_id' => $providerPlanId,
            'name' => $providerPlanId,
            'cpu_cores' => $server->cpu_cores,
            'memory_mb' => $server->memory_mb,
            'disk_gb' => $server->disk_gb,
        ]);
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

    /**
     * Replace a cloud droplet that was created before root-expiry fixes.
     * Destroys the old instance and creates a fresh one with updated cloud-init.
     */
    public function recreateDropletForProvisioning(Server $server, string $publicKey): void
    {
        $server->loadMissing('providerConnection', 'managedPlan', 'credential');
        $adapter = $this->providers->make($server->providerConnection);
        $operation = $server->infrastructureOperations()
            ->where('action', 'create')
            ->latest('id')
            ->first();
        $plan = $this->planFor($server, $operation?->parameters ?? []);

        if (filled($server->provider_resource_id)) {
            try {
                $adapter->destroy($server);
            } catch (\Throwable) {
            }
        }

        $result = $adapter->create($server, $plan, [
            'region' => $server->provider_region,
            'image' => $server->provider_image,
            'user_data' => $this->cloudInit($publicKey),
        ]);

        $server->update([
            'provider_resource_id' => $result['resource_id'],
            'ip_address' => $result['ip_address'],
            'ssh_username' => self::PROVISIONING_SSH_USER,
            'status' => ServerStatus::Provisioning,
            'failure_reason' => null,
        ]);

        if ($server->ip_address === '0.0.0.0') {
            for ($attempt = 0; $attempt < 15; $attempt++) {
                sleep(2);
                $status = $adapter->status($server->fresh());
                if (($status['ip_address'] ?? '0.0.0.0') !== '0.0.0.0') {
                    $server->update(['ip_address' => $status['ip_address']]);
                    break;
                }
            }
        }

        $server->provisioningSteps()->update([
            'status' => 'pending',
            'message' => null,
            'completed_at' => null,
        ]);
    }

    public function publicKeyFor(Server $server): ?string
    {
        $operation = $server->infrastructureOperations()
            ->where('action', 'create')
            ->whereNotNull('parameters->public_key')
            ->latest('id')
            ->first();

        $fromOperation = $operation?->parameters['public_key'] ?? null;
        if (is_string($fromOperation) && $fromOperation !== '') {
            return $fromOperation;
        }

        $server->loadMissing('credential');
        $privateKey = $server->credential?->private_key;
        if (! is_string($privateKey) || $privateKey === '') {
            return null;
        }

        try {
            return trim((string) \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($privateKey)->getPublicKey()->toString('OpenSSH'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function cloudInit(?string $publicKey): string
    {
        $key = $publicKey ? str_replace(["\r", "\n"], '', trim($publicKey)) : null;

        $config = "#cloud-config\n"
            ."package_update: true\n"
            ."packages: [ca-certificates, curl]\n"
            ."ssh_pwauth: false\n"
            ."disable_root: false\n"
            ."chpasswd:\n"
            ."  expire: false\n"
            ."bootcmd:\n"
            ."  - [ sh, -c, 'chage -d -1 root 2>/dev/null || true' ]\n"
            ."  - [ sh, -c, 'chage -I -1 -m 0 -M 99999 root 2>/dev/null || true' ]\n";

        if ($key) {
            $config .= "users:\n"
                ."  - name: root\n"
                ."    lock_passwd: true\n"
                ."    expiredate: -1\n"
                ."    ssh_authorized_keys:\n"
                ."      - {$key}\n"
                ."  - name: ".self::PROVISIONING_SSH_USER."\n"
                ."    groups: [sudo]\n"
                ."    shell: /bin/bash\n"
                ."    lock_passwd: true\n"
                ."    sudo: ALL=(ALL) NOPASSWD:ALL\n"
                ."    ssh_authorized_keys:\n"
                ."      - {$key}\n"
                ."runcmd:\n"
                ."  - mkdir -p /root/.ssh /home/".self::PROVISIONING_SSH_USER."/.ssh /opt/uplary\n"
                ."  - chmod 700 /root/.ssh /home/".self::PROVISIONING_SSH_USER."/.ssh\n"
                ."  - grep -qxF '{$key}' /root/.ssh/authorized_keys 2>/dev/null || echo '{$key}' >> /root/.ssh/authorized_keys\n"
                ."  - grep -qxF '{$key}' /home/".self::PROVISIONING_SSH_USER."/.ssh/authorized_keys 2>/dev/null || echo '{$key}' >> /home/".self::PROVISIONING_SSH_USER."/.ssh/authorized_keys\n"
                ."  - chmod 600 /root/.ssh/authorized_keys /home/".self::PROVISIONING_SSH_USER."/.ssh/authorized_keys\n"
                ."  - chown -R ".self::PROVISIONING_SSH_USER.":".self::PROVISIONING_SSH_USER." /home/".self::PROVISIONING_SSH_USER."/.ssh\n"
                ."  - chage -d -1 root || true\n"
                ."  - chage -I -1 -m 0 -M 99999 root || true\n";
        } else {
            $config .= "runcmd:\n  - mkdir -p /opt/uplary\n  - chage -d -1 root || true\n";
        }

        return $config;
    }
}
