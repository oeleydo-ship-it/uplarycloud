<?php

namespace App\Services\Infrastructure;

use App\Enums\ServerStatus;
use App\Jobs\CreateManagedServerJob;
use App\Models\InfrastructureOperation;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ControlPlaneKeyService;
use Illuminate\Support\Facades\DB;

class ManagedServerProvisioningService
{
    public function __construct(private readonly ControlPlaneKeyService $keys) {}

    /**
     * @return array{0: Server, 1: InfrastructureOperation}
     */
    public function createPending(
        int $tenantId,
        User $actor,
        ProviderConnection $connection,
        ManagedServerPlan $plan,
        string $name,
        string $region,
        string $image,
        bool $prepaid = false,
    ): array {
        $key = $this->keys->generate();

        return DB::transaction(function () use ($tenantId, $actor, $connection, $plan, $name, $region, $image, $key, $prepaid) {
            $server = Server::create([
                'tenant_id' => $tenantId,
                'provider_connection_id' => $connection->id,
                'managed_server_plan_id' => $plan->id,
                'name' => $name,
                'provider' => $connection->provider,
                'ip_address' => '0.0.0.0',
                'location' => strtoupper($region),
                'provider_region' => $region,
                'operating_system' => $image,
                'provider_image' => $image,
                'server_type' => 'managed',
                'status' => ServerStatus::Pending,
                'authentication_method' => 'ssh_key',
                'ssh_username' => ManagedInfrastructureService::PROVISIONING_SSH_USER,
                'cpu_cores' => $plan->cpu_cores,
                'memory_mb' => $plan->memory_mb,
                'disk_gb' => $plan->disk_gb,
                'install_docker' => true,
                'install_proxy' => true,
                'install_monitoring' => true,
            ]);
            $server->credential()->create(['private_key' => $key['private_key']]);
            foreach ([
                'connect' => 'Connecting to cloud server',
                'system' => 'Checking system',
                'docker' => 'Installing Docker',
                'configure' => 'Configuring Docker',
                'proxy' => 'Installing reverse proxy',
                'monitoring' => 'Configuring monitoring',
                'verify' => 'Final verification',
            ] as $stepKey => $label) {
                $server->provisioningSteps()->create([
                    'key' => $stepKey,
                    'label' => $label,
                    'position' => $server->provisioningSteps()->count() + 1,
                ]);
            }

            $operation = InfrastructureOperation::create([
                'tenant_id' => $tenantId,
                'server_id' => $server->id,
                'requested_by' => $actor->id,
                'action' => 'create',
                'status' => 'pending',
                'parameters' => [
                    'plan' => $plan->provider_plan_id,
                    'region' => $region,
                    'image' => $image,
                    'public_key' => $key['public_key'],
                    'monthly_price' => $plan->monthly_price,
                    'billing' => true,
                    'prepaid' => $prepaid,
                ],
            ]);

            return [$server, $operation];
        });
    }

    public function dispatchCreate(InfrastructureOperation $operation): void
    {
        CreateManagedServerJob::dispatch($operation->id);
    }
}
