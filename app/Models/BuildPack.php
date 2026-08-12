<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuildPack extends Model
{
    protected $fillable=['name','slug','framework','icon','accent','detectors','runtime_versions','defaults','active'];
    protected function casts(): array { return ['detectors'=>'array','runtime_versions'=>'array','defaults'=>'array','active'=>'boolean']; }
    public function getRouteKeyName(): string { return 'slug'; }
    public function deployments(): HasMany { return $this->hasMany(ApplicationDeployment::class); }
}
