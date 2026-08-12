<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentRelease extends Model
{
    protected $fillable = ['application_deployment_id','version','image','image_tag','commit','status','is_current','configuration','deployed_at','rolled_back_at'];
    protected function casts(): array { return ['is_current'=>'boolean','configuration'=>'encrypted:array','deployed_at'=>'datetime','rolled_back_at'=>'datetime']; }
    public function deployment(): BelongsTo { return $this->belongsTo(ApplicationDeployment::class, 'application_deployment_id'); }
}
