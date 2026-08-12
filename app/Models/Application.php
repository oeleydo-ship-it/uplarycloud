<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends Model
{
    protected $fillable = ['category_id','name','slug','description','icon','accent','website_url','documentation_url','license_type','license_name','pricing_model','pricing_url','requires_license','docker_image','default_tag','default_port','minimum_cpu','minimum_memory_mb','minimum_disk_gb','featured','active','verified','supports_domain','supports_ssl','supports_backup','install_count'];
    protected function casts(): array { return ['requires_license'=>'boolean','featured'=>'boolean','active'=>'boolean','verified'=>'boolean','supports_domain'=>'boolean','supports_ssl'=>'boolean','supports_backup'=>'boolean','minimum_cpu'=>'decimal:2']; }
    public function getRouteKeyName(): string { return 'slug'; }
    public function category(): BelongsTo { return $this->belongsTo(ApplicationCategory::class); }
    public function template(): HasOne { return $this->hasOne(ApplicationTemplate::class); }
    public function deployments(): HasMany { return $this->hasMany(ApplicationDeployment::class); }

    public function pricingLabel(): string
    {
        return match ($this->pricing_model) {
            'paid' => 'Paid license',
            'freemium' => 'Free + paid',
            default => 'Free',
        };
    }

    public function licenseLabel(): string
    {
        return match ($this->license_type) {
            'commercial' => 'Commercial',
            'source_available' => 'Source available',
            default => 'Open source',
        };
    }

    public function logoPath(): ?string
    {
        $path = public_path("images/apps/{$this->slug}.svg");

        return is_file($path) ? $path : null;
    }

    public function logoUrl(): ?string
    {
        return $this->logoPath() ? asset("images/apps/{$this->slug}.svg") : null;
    }
}
