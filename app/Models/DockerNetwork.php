<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class DockerNetwork extends Model
{
    protected $fillable = ['tenant_id','server_id','uuid','docker_id','name','driver','scope','internal','attachable','subnet','gateway','labels'];
    protected static function booted(): void { static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['internal'=>'boolean','attachable'=>'boolean','labels'=>'array']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function server(): BelongsTo { return $this->belongsTo(Server::class)->withTrashed(); }
    public function containers(): BelongsToMany { return $this->belongsToMany(DockerContainer::class, 'container_network')->withPivot('ip_address')->withTimestamps(); }
}
