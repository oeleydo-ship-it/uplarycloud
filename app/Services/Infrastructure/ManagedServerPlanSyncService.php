<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServerPlan;
use App\Models\ProviderConnection;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\DB;

class ManagedServerPlanSyncService
{
    public const DEFAULT_MARKUP = 100;

    public function __construct(
        private readonly CloudProviderFactory $factory,
        private readonly PlatformSettings $settings,
    ) {}

    public function globalMarkup(): int
    {
        return max(0, min(1000, (int) $this->settings->get('cloud', 'global_markup_percentage', self::DEFAULT_MARKUP)));
    }

    public function setGlobalMarkup(int $markup): void
    {
        $this->settings->put('cloud', [
            'global_markup_percentage' => max(0, min(1000, $markup)),
        ]);
    }

    public function priceFromCost(int $costCents, ?int $markup = null): int
    {
        $markup ??= $this->globalMarkup();

        return (int) round($costCents * (1 + ($markup / 100)));
    }

    /**
     * @return array{synced: int, created: int, updated: int, deactivated: int}
     */
    public function syncConnection(ProviderConnection $connection, ?int $markup = null): array
    {
        abort_unless($connection->platform_managed, 404);

        $markup ??= $this->globalMarkup();
        $catalog = $this->factory->make($connection)->catalog($connection);
        $plans = collect($catalog['plans'] ?? []);
        $seen = [];
        $created = 0;
        $updated = 0;
        $deactivated = 0;
        $position = (int) ManagedServerPlan::where('provider', $connection->provider)->max('position');

        DB::transaction(function () use ($plans, $connection, $markup, &$seen, &$created, &$updated, &$deactivated, &$position) {
            foreach ($plans as $plan) {
                $providerPlanId = (string) ($plan['provider_plan_id'] ?? '');
                if ($providerPlanId === '') {
                    continue;
                }

                $seen[] = $providerPlanId;
                $cost = max(0, (int) ($plan['monthly_cost'] ?? 0));
                $payload = [
                    'name' => (string) ($plan['name'] ?? $providerPlanId),
                    'category' => 'general',
                    'cpu_cores' => max(1, (int) ($plan['cpu_cores'] ?? 1)),
                    'memory_mb' => max(512, (int) ($plan['memory_mb'] ?? 1024)),
                    'disk_gb' => max(10, (int) ($plan['disk_gb'] ?? 10)),
                    'bandwidth_gb' => max(0, (int) ($plan['bandwidth_gb'] ?? 0)),
                    'monthly_cost' => $cost,
                    'markup_percentage' => $markup,
                    'monthly_price' => $this->priceFromCost($cost, $markup),
                    'currency' => strtoupper((string) ($plan['currency'] ?? 'USD')),
                    'regions' => array_values(array_unique($plan['regions'] ?? [])),
                    'images' => array_values(array_unique($plan['images'] ?? ['ubuntu-24.04'])),
                    'active' => true,
                ];

                $existing = ManagedServerPlan::query()
                    ->where('provider', $connection->provider)
                    ->where('provider_plan_id', $providerPlanId)
                    ->first();

                if ($existing) {
                    $existing->update($payload);
                    $updated++;

                    continue;
                }

                $position++;
                ManagedServerPlan::create($payload + [
                    'provider' => $connection->provider,
                    'provider_plan_id' => $providerPlanId,
                    'position' => $position,
                    'featured' => false,
                ]);
                $created++;
            }

            if ($seen !== []) {
                $deactivated = ManagedServerPlan::query()
                    ->where('provider', $connection->provider)
                    ->whereNotIn('provider_plan_id', $seen)
                    ->where('active', true)
                    ->update(['active' => false]);
            }
        });

        return [
            'synced' => count($seen),
            'created' => $created,
            'updated' => $updated,
            'deactivated' => $deactivated,
        ];
    }

    /**
     * @return array{synced: int, created: int, updated: int, deactivated: int, connections: int}
     */
    public function syncAll(?int $markup = null): array
    {
        $markup ??= $this->globalMarkup();
        $totals = ['synced' => 0, 'created' => 0, 'updated' => 0, 'deactivated' => 0, 'connections' => 0];

        $connections = ProviderConnection::query()
            ->where('platform_managed', true)
            ->where('active', true)
            ->whereNotNull('last_verified_at')
            ->get();

        foreach ($connections as $connection) {
            $result = $this->syncConnection($connection, $markup);
            $totals['synced'] += $result['synced'];
            $totals['created'] += $result['created'];
            $totals['updated'] += $result['updated'];
            $totals['deactivated'] += $result['deactivated'];
            $totals['connections']++;
        }

        return $totals;
    }

    public function applyMarkupToActivePlans(int $markup): int
    {
        $this->setGlobalMarkup($markup);
        $count = 0;

        ManagedServerPlan::where('active', true)->each(function (ManagedServerPlan $plan) use ($markup, &$count) {
            $plan->update([
                'markup_percentage' => $markup,
                'monthly_price' => $this->priceFromCost((int) $plan->monthly_cost, $markup),
            ]);
            $count++;
        });

        return $count;
    }
}
