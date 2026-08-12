<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    protected $fillable = ['tenant_id', 'created_by', 'server_id', 'application_deployment_id', 'uuid', 'number', 'subject', 'category', 'priority', 'status', 'description', 'last_replied_at', 'resolved_at'];

    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            $ticket->uuid ??= (string) Str::uuid();
            $ticket->number ??= 'SUP-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        });
    }

    protected function casts(): array
    {
        return ['last_replied_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function server(): BelongsTo { return $this->belongsTo(Server::class)->withTrashed(); }
    public function deployment(): BelongsTo { return $this->belongsTo(ApplicationDeployment::class, 'application_deployment_id')->withTrashed(); }
    public function replies(): HasMany { return $this->hasMany(SupportTicketReply::class); }
}
