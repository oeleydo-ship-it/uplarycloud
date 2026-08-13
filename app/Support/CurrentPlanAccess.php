<?php

namespace App\Support;

use App\Models\Plan;
use App\Services\Billing\PlanLimitService;

class CurrentPlanAccess
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlanLimitService $limits,
    ) {}

    public function plan(): Plan
    {
        return $this->limits->plan($this->context->current());
    }

    public function can(string $feature): bool
    {
        return $this->limits->allowsFeature($this->context->current(), $feature);
    }

    public function quota(string $metric): ?float
    {
        return $this->plan()->limit($metric);
    }

    public function usage(string $metric): float
    {
        return $this->limits->usage($this->context->current(), $metric);
    }

    public function remaining(string $metric): ?float
    {
        $limit = $this->quota($metric);

        return $limit === null ? null : max(0, $limit - $this->usage($metric));
    }

    public function atCapacity(string $metric, float $additional = 1): bool
    {
        return ! $this->limits->allows($this->context->current(), $metric, $additional);
    }

    public function usageLabel(string $metric): string
    {
        $used = $this->formatNumber($this->usage($metric));
        $limit = $this->quota($metric);

        return $limit === null ? $used.' / Unlimited' : $used.' / '.$this->formatNumber($limit);
    }

    private function formatNumber(float $value): string
    {
        return fmod($value, 1.0) === 0.0 ? (string) (int) $value : number_format($value, 1);
    }
}
