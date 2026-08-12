<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationTemplate extends Model
{
    protected $fillable = ['application_id','compose_template','environment_schema','volume_schema','port_schema','healthcheck','restart_policy','installation_notes'];
    protected function casts(): array { return ['environment_schema'=>'array','volume_schema'=>'array','port_schema'=>'array']; }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
}
