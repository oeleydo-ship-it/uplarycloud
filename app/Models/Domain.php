<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Domain extends Model
{
    protected $fillable = ['tenant_id','application_deployment_id','server_id','created_by','uuid','hostname','redirect_to','force_https','ssl_enabled','auto_renew','status','dns_status','dns_record_type','expected_value','resolved_values','last_dns_check_at','dns_verified_at','proxy_status','proxy_configured_at','ssl_status','certificate_provider','certificate_serial','certificate_issued_at','certificate_expires_at','last_renewal_at','failure_reason'];

    protected static function booted(): void
    {
        static::creating(fn (self $domain) => $domain->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['force_https'=>'boolean','ssl_enabled'=>'boolean','auto_renew'=>'boolean','resolved_values'=>'array','last_dns_check_at'=>'datetime','dns_verified_at'=>'datetime','proxy_configured_at'=>'datetime','certificate_issued_at'=>'datetime','certificate_expires_at'=>'datetime','last_renewal_at'=>'datetime'];
    }

    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function deployment(): BelongsTo { return $this->belongsTo(ApplicationDeployment::class, 'application_deployment_id')->withTrashed(); }
    public function server(): BelongsTo { return $this->belongsTo(Server::class)->withTrashed(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function sslTone(): string
    {
        if ($this->hasValidSsl()) {
            return $this->ssl_status === 'expiring' ? 'warning' : 'success';
        }

        return match ($this->ssl_status) {
            'expired', 'failed' => 'failed',
            'pending', 'expiring' => 'warning',
            default => 'muted',
        };
    }

    /** DNS Verified only after a real lookup matched expected_value. */
    public function isDnsVerified(): bool
    {
        return $this->dns_status === 'verified';
    }

    /** SSL Valid only when DNS is verified and the stored cert is still in date. */
    public function hasValidSsl(): bool
    {
        if (! $this->isDnsVerified() || ! in_array($this->ssl_status, ['valid', 'expiring'], true)) {
            return false;
        }

        return $this->certificate_expires_at !== null && $this->certificate_expires_at->isFuture();
    }

    public function dnsStatusLabel(): string
    {
        return match ($this->dns_status) {
            'verified' => 'Verified',
            'mismatch' => 'Unverified',
            default => 'Pending',
        };
    }

    public function sslStatusLabel(): string
    {
        if ($this->ssl_status === 'disabled') {
            return 'Disabled';
        }

        if (! $this->isDnsVerified()) {
            return 'Pending';
        }

        if ($this->ssl_status === 'valid' && ! $this->hasValidSsl()) {
            return 'Pending';
        }

        return ucfirst($this->ssl_status);
    }

    public function connectionApplicationComplete(): bool
    {
        return $this->status === 'active' && $this->isDnsVerified() && (! $this->ssl_enabled || $this->hasValidSsl());
    }

    /**
     * Application step is healthy when DNS + Traefik point at a running container.
     * SSL remains a separate step — do not keep this stuck on "Verifying".
     */
    public function connectionApplicationReady(): bool
    {
        if (! $this->isDnsVerified() || $this->proxy_status !== 'configured') {
            return false;
        }

        $status = $this->deployment?->status;
        $value = $status instanceof \BackedEnum ? $status->value : (string) $status;

        return in_array($value, ['running', 'healthy'], true);
    }

    public function connectionApplicationLabel(): string
    {
        if (! $this->isDnsVerified()) {
            return 'Waiting for DNS';
        }

        if ($this->proxy_status !== 'configured') {
            return 'Waiting for route';
        }

        if ($this->connectionApplicationComplete()) {
            return 'Active';
        }

        if ($this->connectionApplicationReady()) {
            return 'Running';
        }

        if ($this->status === 'failed') {
            return 'Failed';
        }

        $deploymentStatus = $this->deployment?->status;

        return $deploymentStatus ? ucfirst(str_replace('_', ' ', $deploymentStatus)) : 'Pending';
    }

    public function connectionProxyComplete(): bool
    {
        return $this->isDnsVerified() && $this->proxy_status === 'configured';
    }

    public function isRedirect(): bool
    {
        return filled($this->redirect_to);
    }

    public function typeLabel(): string
    {
        return $this->isRedirect() ? 'Redirect' : 'Application';
    }

    public function isPrimary(): bool
    {
        return ! $this->isRedirect() && $this->deployment?->domain === $this->hostname;
    }

    public function isRootDomain(): bool
    {
        return substr_count($this->hostname, '.') === 1;
    }

    public function resolvedApplication(): ?Application
    {
        return $this->deployment?->application;
    }

    public function applicationName(): string
    {
        if ($this->isRedirect()) {
            return '—';
        }

        return $this->resolvedApplication()?->name ?? $this->deployment?->name ?? '—';
    }

    public function applicationDetail(): ?string
    {
        if ($this->isRedirect()) {
            return null;
        }

        if ($application = $this->resolvedApplication()) {
            return $application->category?->name ?? $this->deployment?->name;
        }

        return $this->deployment?->buildPack?->name ?? $this->deployment?->framework;
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->certificate_expires_at
            ? (int) now()->startOfDay()->diffInDays($this->certificate_expires_at->startOfDay(), false)
            : null;
    }

    public function isExpiringSoon(): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days >= 0 && $days <= 30;
    }
}
