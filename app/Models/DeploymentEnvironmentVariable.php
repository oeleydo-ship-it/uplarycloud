<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentEnvironmentVariable extends Model
{
    protected $fillable = ['application_deployment_id','key','value','secret','description'];
    protected function casts(): array { return ['value'=>'encrypted','secret'=>'boolean']; }
    public function deployment(): BelongsTo { return $this->belongsTo(ApplicationDeployment::class, 'application_deployment_id'); }
    public function maskedValue(): string { return $this->secret ? '••••••••••••' : (string) $this->value; }
}
