<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->uuid ??= (string) Str::uuid();
            $tenant->slug ??= Str::slug($tenant->name).'-'.Str::lower(Str::random(5));
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(ApplicationDeployment::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
    public function backups(): HasMany { return $this->hasMany(Backup::class); }
    public function alertRules(): HasMany { return $this->hasMany(AlertRule::class); }
    public function operationalLogs(): HasMany { return $this->hasMany(OperationalLog::class); }
    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
    public function invoices(): HasMany { return $this->hasMany(BillingInvoice::class); }
    public function paymentMethods(): HasMany { return $this->hasMany(PaymentMethod::class); }
    public function usageRecords(): HasMany { return $this->hasMany(UsageRecord::class); }
    public function invitations(): HasMany { return $this->hasMany(TeamInvitation::class); }
    public function providerConnections(): HasMany { return $this->hasMany(ProviderConnection::class); }
    public function infrastructureOperations(): HasMany { return $this->hasMany(InfrastructureOperation::class); }
    public function infrastructureCharges(): HasMany { return $this->hasMany(InfrastructureCharge::class); }
    public function supportTickets(): HasMany { return $this->hasMany(SupportTicket::class); }
    public function currentSubscription(): ?Subscription { return $this->subscriptions()->with('plan')->whereIn('status', ['active','trialing','past_due'])->latest()->first(); }
    public function latestSubscription(): HasOne { return $this->hasOne(Subscription::class)->latestOfMany(); }
    public function entitledSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->with('plan')
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->get()
            ->first(fn (Subscription $subscription) => $subscription->active() && $subscription->plan !== null);
    }
}
