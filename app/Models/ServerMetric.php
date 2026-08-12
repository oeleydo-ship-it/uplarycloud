<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetric extends Model
{
    public $timestamps = false;
    protected $fillable = ['server_id', 'cpu_percent', 'memory_percent', 'disk_percent', 'load_average', 'network_in_bytes', 'network_out_bytes', 'recorded_at'];
    protected function casts(): array { return ['recorded_at' => 'datetime']; }
    public function server(): BelongsTo { return $this->belongsTo(Server::class); }
}
