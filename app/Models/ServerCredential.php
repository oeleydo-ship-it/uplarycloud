<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerCredential extends Model
{
    protected $fillable = ['server_id', 'private_key', 'password', 'passphrase'];
    protected $hidden = ['private_key', 'password', 'passphrase'];

    protected function casts(): array
    {
        return ['private_key' => 'encrypted', 'password' => 'encrypted', 'passphrase' => 'encrypted'];
    }

    public function server(): BelongsTo { return $this->belongsTo(Server::class); }
}
