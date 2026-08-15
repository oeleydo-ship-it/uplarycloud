<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ManagedServerOrder extends Model
{
    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'provider_connection_id',
        'managed_server_plan_id',
        'name',
        'region',
        'image',
        'amount',
        'currency',
        'status',
        'stripe_checkout_session_id',
        'server_id',
        'paid_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (ManagedServerOrder $order) => $order->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class);
    }

    public function managedPlan(): BelongsTo
    {
        return $this->belongsTo(ManagedServerPlan::class, 'managed_server_plan_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isPendingPayment(): bool
    {
        return $this->status === 'pending_payment';
    }
}
