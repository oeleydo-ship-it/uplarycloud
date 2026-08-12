<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentStep extends Model
{
    protected $fillable = ['application_deployment_id','key','name','position','status','started_at','completed_at','error'];
    protected function casts(): array { return ['started_at'=>'datetime','completed_at'=>'datetime']; }
    public function deployment(): BelongsTo { return $this->belongsTo(ApplicationDeployment::class, 'application_deployment_id'); }
}
