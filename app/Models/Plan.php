<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Plan extends Model
{
    protected $fillable = ['uuid', 'name', 'slug', 'description', 'monthly_price', 'yearly_price', 'currency', 'stripe_monthly_price_id', 'stripe_yearly_price_id', 'limits', 'features', 'gates', 'featured', 'active', 'position'];

    protected static function booted(): void
    {
        static::creating(fn (Plan $plan) => $plan->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['limits' => 'array', 'features' => 'array', 'gates' => 'array', 'featured' => 'boolean', 'active' => 'boolean'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function price(string $cycle): int
    {
        return $cycle === 'yearly' ? $this->yearly_price : $this->monthly_price;
    }

    public function limit(string $metric): ?float
    {
        $value = $this->limits[$metric] ?? null;

        return $value === null || $value === '' || $value === 'unlimited' ? null : (float) $value;
    }

    public function allowsFeature(string $feature): bool
    {
        $gates = $this->gates ?? [];

        return array_key_exists($feature, $gates) ? (bool) $gates[$feature] : true;
    }
}
