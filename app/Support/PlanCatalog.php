<?php

namespace App\Support;

class PlanCatalog
{
    public static function gates(): array
    {
        return config('plan_features.gates', []);
    }

    public static function quotas(): array
    {
        return config('plan_features.quotas', []);
    }

    public static function gateKeys(): array
    {
        return array_keys(static::gates());
    }

    public static function quotaKeys(): array
    {
        return array_keys(static::quotas());
    }

    public static function gate(string $key): array
    {
        return static::gates()[$key] ?? ['label' => str($key)->replace('_', ' ')->title()->toString(), 'description' => ''];
    }

    public static function quota(string $key): array
    {
        return static::quotas()[$key] ?? ['label' => str($key)->replace('_', ' ')->title()->toString(), 'unit' => 'count'];
    }

    public static function label(string $key): string
    {
        return static::gate($key)['label'] ?? static::quota($key)['label'];
    }

    public static function defaultsFor(string $slug): array
    {
        $defaults = config('plan_features.defaults.'.$slug);

        if (! is_array($defaults)) {
            return [
                'gates' => array_fill_keys(static::gateKeys(), true),
                'limits' => array_fill_keys(static::quotaKeys(), null),
            ];
        }

        return [
            'gates' => array_replace(array_fill_keys(static::gateKeys(), false), $defaults['gates'] ?? []),
            'limits' => array_replace(array_fill_keys(static::quotaKeys(), null), $defaults['limits'] ?? []),
        ];
    }

    public static function groupedGates(): array
    {
        return collect(static::gates())->groupBy(fn (array $gate) => $gate['group'] ?? 'Features')->all();
    }

    public static function groupedQuotas(): array
    {
        return collect(static::quotas())->groupBy(fn (array $quota) => $quota['group'] ?? 'Limits')->all();
    }

    public static function navGate(string $route): ?string
    {
        foreach (static::gates() as $key => $gate) {
            if (($gate['nav'] ?? null) === $route) {
                return $key;
            }
        }

        return null;
    }
}
