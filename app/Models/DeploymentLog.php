<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentLog extends Model
{
    protected $fillable = ['application_deployment_id','level','message','context','occurred_at'];
    protected function casts(): array { return ['context'=>'array','occurred_at'=>'datetime']; }
    public function deployment(): BelongsTo { return $this->belongsTo(ApplicationDeployment::class, 'application_deployment_id'); }
}
