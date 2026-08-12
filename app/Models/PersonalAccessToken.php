<?php
namespace App\Models;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'revoked_at', 'tenant_id', 'environment', 'ip_restrictions'];
    protected function casts(): array { return ['abilities'=>'json','last_used_at'=>'datetime','expires_at'=>'datetime','revoked_at'=>'datetime','ip_restrictions'=>'array']; }
    public function getStatusAttribute(): string
    {
        if ($this->revoked_at) return 'revoked';
        if ($this->expires_at?->isPast()) return 'expired';
        return 'active';
    }
    public function getDisplayPrefixAttribute(): string { return 'upl_'.substr($this->token, 0, 8); }
}
