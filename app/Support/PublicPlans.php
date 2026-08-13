<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Support\Collection;
use Throwable;

class PublicPlans
{
    /**
     * Active subscription plans for the public pricing page.
     * Falls back to catalog defaults when the table is empty or unavailable.
     *
     * @return Collection<int, object>
     */
    public static function all(): Collection
    {
        try {
            $plans = Plan::query()->where('active', true)->orderBy('position')->orderBy('monthly_price')->get();

            if ($plans->isNotEmpty()) {
                return $plans;
            }
        } catch (Throwable) {
            // Plans may be unavailable during install or a partial migration.
        }

        return collect(static::fallback())->map(fn (array $plan) => (object) $plan);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fallback(): array
    {
        $catalog = [
            ['Free', 'free', 'For personal experiments and a single server.', 0, 0, ['Core Docker management', 'Community support', '24-hour monitoring'], false],
            ['Starter', 'starter', 'For independent developers shipping real projects.', 1200, 11520, ['Automated backups', '7-day monitoring', 'Email alerts', 'API access'], false],
            ['Pro', 'pro', 'For growing teams running production workloads.', 2900, 27840, ['Everything in Starter', '30-day monitoring', 'Priority support', 'Advanced API scopes', 'S3 destinations'], true],
            ['Business', 'business', 'For organizations with demanding infrastructure.', 7900, 75840, ['Everything in Pro', '90-day monitoring', 'Audit exports', 'SLA support', 'Custom onboarding'], false],
        ];

        return collect($catalog)->map(function (array $row, int $index): array {
            [$name, $slug, $description, $monthly, $yearly, $features, $featured] = $row;
            $defaults = PlanCatalog::defaultsFor($slug);

            return [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'monthly_price' => $monthly,
                'yearly_price' => $yearly,
                'currency' => 'USD',
                'features' => $features,
                'featured' => $featured,
                'limits' => $defaults['limits'],
                'gates' => $defaults['gates'],
                'position' => $index + 1,
            ];
        })->all();
    }
}
