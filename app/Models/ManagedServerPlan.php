<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ManagedServerPlan extends Model
{
    protected $fillable = ['uuid', 'provider', 'provider_plan_id', 'name', 'category', 'cpu_cores', 'memory_mb', 'disk_gb', 'bandwidth_gb', 'monthly_cost', 'markup_percentage', 'monthly_price', 'currency', 'regions', 'images', 'featured', 'active', 'position'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['regions' => 'array', 'images' => 'array', 'featured' => 'boolean', 'active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function priceLabel(): string
    {
        $symbol = strtoupper((string) $this->currency) === 'EUR' ? '€' : '$';

        return $symbol.number_format($this->monthly_price / 100, 2);
    }
}
