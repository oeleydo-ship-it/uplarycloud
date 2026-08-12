<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerProvisioningStep extends Model
{
    protected $fillable = ['server_id', 'key', 'label', 'position', 'status', 'message', 'started_at', 'completed_at'];
    protected function casts(): array { return ['started_at' => 'datetime', 'completed_at' => 'datetime']; }
    public function server(): BelongsTo { return $this->belongsTo(Server::class); }
}
