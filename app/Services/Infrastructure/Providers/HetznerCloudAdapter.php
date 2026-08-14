<?php

namespace App\Services\Infrastructure\Providers;

use App\Contracts\Infrastructure\CloudProviderAdapterInterface;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HetznerCloudAdapter implements CloudProviderAdapterInterface
{
    private function client(ProviderConnection $connection): PendingRequest
    {
        return Http::baseUrl('https://api.hetzner.cloud/v1')
            ->withToken($connection->api_token)
            ->acceptJson()
            ->timeout(45)
            ->retry(2, 500);
    }

    private function connection(Server $server): ProviderConnection
    {
        return $server->providerConnection ?? throw new RuntimeException('Provider connection is missing.');
    }

    public function verify(ProviderConnection $connection): array
    {
        $response = $this->client($connection)->get('/servers', ['per_page' => 1])->throw();

        return ['success' => true, 'account' => 'Hetzner Cloud project', 'servers_visible' => count($response->json('servers', []))];
    }

    public function catalog(ProviderConnection $connection): array
    {
        $images = $this->systemImages($connection);
        $types = $this->client($connection)->get('/server_types')->throw()->json('server_types', []);
        $plans = [];

        foreach ($types as $type) {
            $slug = (string) ($type['name'] ?? '');
            if ($slug === '' || ($type['architecture'] ?? 'x86') !== 'x86') {
                continue;
            }

            // Common provisionable families; skip deprecated specialty types.
            if (! preg_match('/^(cx|cpx|cax|ccx)\d+$/i', $slug)) {
                continue;
            }

            $regions = [];
            $monthly = null;
            foreach ($type['prices'] ?? [] as $price) {
                $location = (string) ($price['location'] ?? '');
                if ($location !== '') {
                    $regions[] = $location;
                }
                $gross = (float) ($price['price_monthly']['gross'] ?? 0);
                if ($monthly === null || ($gross > 0 && $gross < $monthly)) {
                    $monthly = $gross;
                }
            }

            $memoryGb = (float) ($type['memory'] ?? 1);
            if ($regions === [] || ! ($monthly > 0) || $memoryGb < 1 || $memoryGb > 64) {
                continue;
            }

            $plans[] = [
                'provider_plan_id' => $slug,
                'name' => strtoupper($slug).' · '.(int) ($type['cores'] ?? 1).' vCPU / '.$memoryGb.' GB',
                'cpu_cores' => max(1, (int) ($type['cores'] ?? 1)),
                'memory_mb' => max(1024, (int) round($memoryGb * 1024)),
                'disk_gb' => max(10, (int) ($type['disk'] ?? 20)),
                'bandwidth_gb' => 20000,
                'monthly_cost' => max(0, (int) round($monthly * 100)),
                'currency' => 'EUR',
                'regions' => array_values(array_unique($regions)),
                'images' => $images,
            ];
        }

        usort($plans, function (array $a, array $b): int {
            return [$a['monthly_cost'], $a['memory_mb'], $a['cpu_cores'], $a['provider_plan_id']]
                <=> [$b['monthly_cost'], $b['memory_mb'], $b['cpu_cores'], $b['provider_plan_id']];
        });

        return ['plans' => $plans];
    }

    public function create(Server $server, ManagedServerPlan $plan, array $options): array
    {
        $response = $this->client($this->connection($server))->withHeaders(['Idempotency-Key' => 'uplary-'.$server->uuid])->post('/servers', [
            'name' => (string) str($server->name)->slug(),
            'location' => $options['region'],
            'server_type' => $plan->provider_plan_id,
            'image' => $options['image'],
            'user_data' => $options['user_data'] ?? null,
            'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true],
            'labels' => ['managed' => 'true', 'control-plane' => 'uplary'],
        ])->throw()->json('server');

        return [
            'resource_id' => (string) $response['id'],
            'ip_address' => $response['public_net']['ipv4']['ip'] ?? '0.0.0.0',
            'status' => $response['status'] ?? 'initializing',
            'region' => $options['region'],
            'image' => $options['image'],
        ];
    }

    public function status(Server $server): array
    {
        $serverPayload = $this->client($this->connection($server))->get('/servers/'.$server->provider_resource_id)->throw()->json('server');

        return [
            'resource_id' => (string) $serverPayload['id'],
            'status' => $serverPayload['status'],
            'ip_address' => $serverPayload['public_net']['ipv4']['ip'] ?? $server->ip_address,
        ];
    }

    private function action(Server $server, string $action, array $payload = []): array
    {
        $this->client($this->connection($server))->post('/servers/'.$server->provider_resource_id.'/actions/'.$action, $payload)->throw();

        return ['resource_id' => $server->provider_resource_id, 'status' => $action];
    }

    public function restart(Server $server): array
    {
        return $this->action($server, 'reboot');
    }

    public function powerOff(Server $server): array
    {
        return $this->action($server, 'shutdown');
    }

    public function resize(Server $server, ManagedServerPlan $plan): array
    {
        return $this->action($server, 'change_type', ['server_type' => $plan->provider_plan_id, 'upgrade_disk' => true]);
    }

    public function rebuild(Server $server, string $image): array
    {
        return $this->action($server, 'rebuild', ['image' => $image]);
    }

    public function destroy(Server $server): array
    {
        $this->client($this->connection($server))->delete('/servers/'.$server->provider_resource_id)->throw();

        return ['resource_id' => $server->provider_resource_id, 'status' => 'deleted'];
    }

    public function destroyWithAssociatedResources(Server $server): array
    {
        $client = $this->client($this->connection($server));
        $remote = $client->get('/servers/'.$server->provider_resource_id)->throw()->json('server');
        $volumeIds = collect($remote['volumes'] ?? [])->map(fn ($id) => (string) $id)->filter()->values();
        $primaryIpIds = collect([
            $remote['public_net']['ipv4']['id'] ?? null,
            $remote['public_net']['ipv6']['id'] ?? null,
        ])->map(fn ($id) => (string) $id)->filter()->unique()->values();

        $client->delete('/servers/'.$server->provider_resource_id)->throw();

        foreach ($volumeIds as $volumeId) {
            $response = $client->delete('/volumes/'.$volumeId);
            if (! $response->successful() && $response->status() !== 404) {
                $response->throw();
            }
        }

        foreach ($primaryIpIds as $primaryIpId) {
            $response = $client->delete('/primary_ips/'.$primaryIpId);
            if (! $response->successful() && $response->status() !== 404) {
                $response->throw();
            }
        }

        return [
            'resource_id' => $server->provider_resource_id,
            'status' => 'deleted',
            'associated_resources' => [
                'volumes' => $volumeIds->all(),
                'primary_ips' => $primaryIpIds->all(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function systemImages(ProviderConnection $connection): array
    {
        $allowed = ['ubuntu-24.04', 'ubuntu-22.04', 'debian-12'];
        $found = [];

        foreach ($this->client($connection)->get('/images', ['type' => 'system'])->throw()->json('images', []) as $image) {
            $name = (string) ($image['name'] ?? '');
            if (in_array($name, $allowed, true)) {
                $found[$name] = true;
            }
        }

        $images = array_keys($found);

        return $images !== [] ? $images : ['ubuntu-24.04'];
    }
}
