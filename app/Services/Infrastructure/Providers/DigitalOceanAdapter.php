<?php

namespace App\Services\Infrastructure\Providers;

use App\Contracts\Infrastructure\CloudProviderAdapterInterface;
use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Models\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DigitalOceanAdapter implements CloudProviderAdapterInterface
{
    private function client(ProviderConnection $connection): PendingRequest
    {
        return Http::baseUrl('https://api.digitalocean.com/v2')
            ->withToken($connection->api_token)
            ->acceptJson()
            ->timeout(45)
            ->retry(2, 500, function ($exception): bool {
                if ($exception instanceof RequestException && $exception->response->status() === 404) {
                    return false;
                }

                return true;
            }, throw: false);
    }

    private function connection(Server $server): ProviderConnection
    {
        return $server->providerConnection ?? throw new RuntimeException('Provider connection is missing.');
    }

    public function verify(ProviderConnection $connection): array
    {
        $account = $this->client($connection)->get('/account')->throw()->json('account');

        return ['success' => true, 'account' => $account['email'] ?? $account['uuid'] ?? 'connected'];
    }

    public function catalog(ProviderConnection $connection): array
    {
        $images = $this->distributionImages($connection);
        $plans = [];
        $page = 1;

        do {
            $response = $this->client($connection)->get('/sizes', ['page' => $page, 'per_page' => 200])->throw()->json();
            foreach ($response['sizes'] ?? [] as $size) {
                if (! $this->isProvisionableSize($size)) {
                    continue;
                }

                $slug = (string) ($size['slug'] ?? '');
                $regions = collect($size['regions'] ?? [])->filter()->values()->all();

                $plans[] = [
                    'provider_plan_id' => $slug,
                    'name' => $this->sizeLabel($slug, (int) ($size['vcpus'] ?? 1), (int) ($size['memory'] ?? 1024)),
                    'cpu_cores' => max(1, (int) ($size['vcpus'] ?? 1)),
                    'memory_mb' => max(1024, (int) ($size['memory'] ?? 1024)),
                    'disk_gb' => max(10, (int) ($size['disk'] ?? 10)),
                    'bandwidth_gb' => max(0, (int) round(((float) ($size['transfer'] ?? 0)) * 1000)),
                    'monthly_cost' => max(0, (int) round(((float) ($size['price_monthly'] ?? 0)) * 100)),
                    'currency' => 'USD',
                    'regions' => $regions,
                    'images' => $images,
                ];
            }
            $page++;
        } while (! empty($response['links']['pages']['next']));

        usort($plans, function (array $a, array $b): int {
            return [$a['monthly_cost'], $a['memory_mb'], $a['cpu_cores'], $a['provider_plan_id']]
                <=> [$b['monthly_cost'], $b['memory_mb'], $b['cpu_cores'], $b['provider_plan_id']];
        });

        return ['plans' => $plans];
    }

    /**
     * Keep generally useful droplet types: available, non-GPU, ≥1 GB RAM, ≤64 GB,
     * and common DigitalOcean size families (basic / CPU / general / memory / storage).
     *
     * @param  array<string, mixed>  $size
     */
    private function isProvisionableSize(array $size): bool
    {
        if (! ($size['available'] ?? false)) {
            return false;
        }

        $slug = strtolower((string) ($size['slug'] ?? ''));
        if ($slug === '' || str_contains($slug, 'gpu')) {
            return false;
        }

        $memoryMb = (int) ($size['memory'] ?? 0);
        if ($memoryMb < 1024 || $memoryMb > 65536) {
            return false;
        }

        $regions = array_filter($size['regions'] ?? []);
        if ($regions === []) {
            return false;
        }

        if ((float) ($size['price_monthly'] ?? 0) <= 0) {
            return false;
        }

        // Prefer mainstream families; skip obscure legacy / specialty SKUs when hundreds exist.
        $families = ['s-', 'c-', 'c2-', 'c3-', 'g-', 'g2-', 'g3-', 'm-', 'm2-', 'm3-', 'm6-', 'so-', 'so1_5-', 'gd-'];
        foreach ($families as $family) {
            if (str_starts_with($slug, $family)) {
                return true;
            }
        }

        return false;
    }

    public function create(Server $server, ManagedServerPlan $plan, array $options): array
    {
        $response = $this->client($this->connection($server))->withHeaders(['Idempotency-Key' => 'uplary-'.$server->uuid])->post('/droplets', [
            'name' => (string) str($server->name)->slug(),
            'region' => $options['region'],
            'size' => $plan->provider_plan_id,
            'image' => $this->image($options['image']),
            'user_data' => $options['user_data'] ?? null,
            'monitoring' => true,
            'ipv6' => true,
            'tags' => ['uplary-managed'],
        ])->throw()->json('droplet');

        return [
            'resource_id' => (string) $response['id'],
            'ip_address' => collect($response['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? '0.0.0.0',
            'status' => $response['status'] ?? 'new',
            'region' => $options['region'],
            'image' => $options['image'],
        ];
    }

    public function status(Server $server): array
    {
        $droplet = $this->client($this->connection($server))->get('/droplets/'.$server->provider_resource_id)->throw()->json('droplet');

        return [
            'resource_id' => (string) $droplet['id'],
            'status' => $droplet['status'],
            'ip_address' => collect($droplet['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? $server->ip_address,
        ];
    }

    private function action(Server $server, string $type, array $extra = []): array
    {
        $this->client($this->connection($server))->post('/droplets/'.$server->provider_resource_id.'/actions', ['type' => $type] + $extra)->throw();

        return ['resource_id' => $server->provider_resource_id, 'status' => $type];
    }

    public function restart(Server $server): array
    {
        return $this->action($server, 'reboot');
    }

    public function powerOff(Server $server): array
    {
        return $this->action($server, 'power_off');
    }

    public function resize(Server $server, ManagedServerPlan $plan): array
    {
        return $this->action($server, 'resize', ['size' => $plan->provider_plan_id, 'disk' => true]);
    }

    public function rebuild(Server $server, string $image): array
    {
        return $this->action($server, 'rebuild', ['image' => $this->image($image)]);
    }

    public function destroy(Server $server): array
    {
        $response = $this->client($this->connection($server))->delete('/droplets/'.$server->provider_resource_id);
        if ($response->notFound()) {
            return $this->alreadyDeletedResult($server);
        }

        $response->throw();

        return ['resource_id' => $server->provider_resource_id, 'status' => 'deleted'];
    }

    public function destroyWithAssociatedResources(Server $server): array
    {
        $connection = $this->connection($server);
        $path = '/droplets/'.$server->provider_resource_id.'/destroy_with_associated_resources';

        if (! $this->dropletExists($connection, $server)) {
            return $this->alreadyDeletedResult($server);
        }

        try {
            $destroyResponse = $this->client($connection)
                ->withHeaders(['X-Dangerous' => 'true'])
                ->delete($path.'/dangerous');
        } catch (RequestException $exception) {
            if ($exception->response->notFound()) {
                return $this->alreadyDeletedResult($server);
            }

            throw $exception;
        }

        if ($destroyResponse->notFound()) {
            return $this->alreadyDeletedResult($server);
        }

        $destroyResponse->throw();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            if ($attempt > 0) {
                sleep(1);
            }

            if (! $this->dropletExists($connection, $server)) {
                return [
                    'resource_id' => $server->provider_resource_id,
                    'status' => 'deleted',
                    'associated_resources' => [],
                    'completed_at' => now()->toIso8601String(),
                    'confirmed_by' => 'droplet_absence',
                ];
            }

            try {
                $statusResponse = $this->client($connection)->get($path.'/status');
            } catch (RequestException $exception) {
                if ($exception->response->notFound()) {
                    return $this->alreadyDeletedResult($server);
                }

                throw $exception;
            }

            if ($statusResponse->notFound()) {
                return $this->alreadyDeletedResult($server);
            }

            $status = $statusResponse->throw()->json();
            if (blank($status['completed_at'] ?? null)) {
                continue;
            }

            if ((int) ($status['failures'] ?? 0) > 0) {
                throw new RuntimeException('DigitalOcean could not destroy one or more associated resources. Check the provider account and retry.');
            }

            return [
                'resource_id' => $server->provider_resource_id,
                'status' => 'deleted',
                'associated_resources' => $status['resources'] ?? [],
                'completed_at' => $status['completed_at'],
            ];
        }

        if (! $this->dropletExists($connection, $server)) {
            return [
                'resource_id' => $server->provider_resource_id,
                'status' => 'deleted',
                'associated_resources' => [],
                'completed_at' => now()->toIso8601String(),
                'confirmed_by' => 'droplet_absence_after_wait',
            ];
        }

        return $this->destroy($server);
    }

    private function dropletExists(ProviderConnection $connection, Server $server): bool
    {
        $response = $this->client($connection)->get('/droplets/'.$server->provider_resource_id);
        if ($response->notFound()) {
            return false;
        }

        $response->throw();

        return true;
    }

    private function alreadyDeletedResult(Server $server): array
    {
        return [
            'resource_id' => $server->provider_resource_id,
            'status' => 'already_deleted_at_provider',
            'associated_resources' => [],
        ];
    }

    private function image(string $image): string
    {
        return match ($image) {
            'ubuntu-24.04' => 'ubuntu-24-04-x64',
            'ubuntu-22.04' => 'ubuntu-22-04-x64',
            'debian-12' => 'debian-12-x64',
            default => $image,
        };
    }

    /**
     * @return list<string>
     */
    private function distributionImages(ProviderConnection $connection): array
    {
        $allowed = [
            'ubuntu-24-04-x64' => 'ubuntu-24.04',
            'ubuntu-22-04-x64' => 'ubuntu-22.04',
            'debian-12-x64' => 'debian-12',
        ];
        $found = [];
        $page = 1;

        do {
            $response = $this->client($connection)->get('/images', [
                'type' => 'distribution',
                'page' => $page,
                'per_page' => 200,
            ])->throw()->json();

            foreach ($response['images'] ?? [] as $image) {
                $slug = (string) ($image['slug'] ?? '');
                if (isset($allowed[$slug])) {
                    $found[$allowed[$slug]] = true;
                }
            }
            $page++;
        } while (! empty($response['links']['pages']['next']));

        $images = array_keys($found);

        return $images !== [] ? $images : ['ubuntu-24.04', 'ubuntu-22.04'];
    }

    private function sizeLabel(string $slug, int $cpu, int $memoryMb): string
    {
        $ram = $memoryMb >= 1024 ? round($memoryMb / 1024, 1).' GB' : $memoryMb.' MB';

        return strtoupper(str_replace('-', ' ', $slug)).' · '.$cpu.' vCPU / '.$ram;
    }
}
