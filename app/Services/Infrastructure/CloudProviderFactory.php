<?php

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\CloudProviderAdapterInterface;
use App\Models\ProviderConnection;
use App\Services\Infrastructure\Providers\DigitalOceanAdapter;
use App\Services\Infrastructure\Providers\FakeCloudProviderAdapter;
use App\Services\Infrastructure\Providers\HetznerCloudAdapter;
use RuntimeException;

class CloudProviderFactory
{
    public function make(ProviderConnection $connection): CloudProviderAdapterInterface
    {
        // Tenant BYO tokens always talk to the real provider. The fake driver is only
        // for platform-managed simulation, never for a customer's DigitalOcean/Hetzner key.
        if ($connection->platform_managed && config('infrastructure.managed_driver') === 'fake') {
            if (config('infrastructure.driver') === 'ssh' && ! app()->environment('testing')) {
                throw new RuntimeException('Managed provisioning is configured for simulation while live SSH is enabled. Set MANAGED_INFRASTRUCTURE_DRIVER=api and restart the queue workers.');
            }

            return app(FakeCloudProviderAdapter::class);
        }

        return match ($connection->provider) {
            'digitalocean' => app(DigitalOceanAdapter::class),
            'hetzner' => app(HetznerCloudAdapter::class),
            default => throw new RuntimeException('The '.$connection->provider.' managed adapter is not enabled for production provisioning.'),
        };
    }
}
