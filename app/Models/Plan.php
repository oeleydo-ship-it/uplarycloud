<?php

namespace App\Models;

use App\Support\PlanCatalog;
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

    public function stripePriceId(string $cycle): ?string
    {
        $fromPlan = $cycle === 'yearly' ? $this->stripe_yearly_price_id : $this->stripe_monthly_price_id;
        if (filled($fromPlan)) {
            return (string) $fromPlan;
        }

        $fromConfig = config('billing.stripe.prices.'.$this->slug.'_'.$cycle);

        return filled($fromConfig) ? (string) $fromConfig : null;
    }

    public function hasStripePricesConfigured(): bool
    {
        return filled($this->stripePriceId('monthly')) && filled($this->stripePriceId('yearly'));
    }

    public function limit(string $metric): ?float
    {
        $value = $this->limits[$metric] ?? null;

        return $value === null || $value === '' || $value === 'unlimited' ? null : (float) $value;
    }

    public function allowsFeature(string $feature): bool
    {
        // Plans created before feature gates were introduced had a NULL value.
        // Keep those legacy records permissive until an admin saves explicit gates.
        if ($this->gates === null) {
            return true;
        }

        $gates = $this->gates ?? [];

        if (array_key_exists($feature, $gates)) {
            return (bool) $gates[$feature];
        }

        return (bool) (PlanCatalog::defaultsFor($this->slug)['gates'][$feature] ?? false);
    }
}
