<?php

namespace Tests\Unit;

use App\Models\ProviderConnection;
use App\Models\Server;
use App\Models\Tenant;
use App\Services\Infrastructure\Providers\DigitalOceanAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalOceanDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_with_associated_resources_succeeds_when_droplet_disappears_before_status_completes(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/droplets/123/destroy_with_associated_resources/dangerous' => Http::response([], 202),
            'https://api.digitalocean.com/v2/droplets/123/destroy_with_associated_resources/status' => Http::response([
                'completed_at' => null,
                'failures' => 0,
            ], 200),
            'https://api.digitalocean.com/v2/droplets/123' => Http::sequence()
                ->push(['droplet' => ['id' => 123, 'status' => 'active', 'networks' => ['v4' => []]]], 200)
                ->push([], 404),
        ]);

        $server = $this->digitalOceanServer();

        $result = app(DigitalOceanAdapter::class)->destroyWithAssociatedResources($server);

        $this->assertSame('deleted', $result['status']);
        $this->assertSame('droplet_absence', $result['confirmed_by'] ?? null);
    }

    public function test_force_destroy_treats_missing_droplet_as_already_deleted(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/droplets/123' => Http::response([], 404),
        ]);

        $server = $this->digitalOceanServer();

        $result = app(DigitalOceanAdapter::class)->destroy($server);

        $this->assertSame('already_deleted_at_provider', $result['status']);
    }

    private function digitalOceanServer(): Server
    {
        $tenant = Tenant::create(['name' => 'Test Tenant']);

        $connection = ProviderConnection::create([
            'tenant_id' => $tenant->id,
            'name' => 'DO',
            'provider' => 'digitalocean',
            'api_token' => 'token',
            'active' => true,
            'platform_managed' => false,
            'last_verified_at' => now(),
        ]);

        return Server::create([
            'tenant_id' => $tenant->id,
            'provider_connection_id' => $connection->id,
            'name' => 'rrrrr',
            'provider' => 'digitalocean',
            'provider_resource_id' => '123',
            'ip_address' => '159.223.216.129',
            'operating_system' => 'ubuntu-22.04',
            'server_type' => 'byos',
            'status' => 'online',
            'authentication_method' => 'ssh_key',
        ]);
    }
}
