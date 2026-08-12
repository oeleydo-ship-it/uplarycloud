<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DockerComposeProject extends Model
{
    protected $fillable = ['tenant_id','server_id','uuid','name','slug','compose_content','environment','status','deployed_at','last_error'];
    protected static function booted(): void { static::creating(function ($model) { $model->uuid ??= (string) Str::uuid(); $model->slug ??= Str::slug($model->name).'-'.Str::lower(Str::random(5)); }); }
    protected function casts(): array { return ['compose_content'=>'encrypted','environment'=>'encrypted:array','deployed_at'=>'datetime']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function server(): BelongsTo { return $this->belongsTo(Server::class)->withTrashed(); }
}
