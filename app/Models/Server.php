<?php

namespace App\Models;

use App\Enums\ServerAuthenticationMethod;
use App\Enums\ServerProvider;
use App\Enums\ServerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Server extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'provider', 'server_group', 'tags', 'ip_address',
        'location', 'operating_system', 'server_type', 'status', 'ssh_port', 'ssh_username',
        'authentication_method', 'connection_timeout', 'cpu_cores', 'memory_mb', 'disk_gb',
        'docker_version', 'docker_compose_version', 'last_seen_at', 'provisioned_at', 'failure_reason',
        'proxy_status', 'proxy_version', 'proxy_network', 'proxy_installed_at', 'provider_connection_id',
        'managed_server_plan_id', 'provider_resource_id', 'provider_region', 'provider_image', 'provider_created_at',
        'install_docker', 'install_proxy', 'install_monitoring',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Server $server) => $server->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'provider' => ServerProvider::class, 'status' => ServerStatus::class,
            'authentication_method' => ServerAuthenticationMethod::class, 'tags' => 'array',
            'last_seen_at' => 'datetime', 'provisioned_at' => 'datetime',
            'proxy_installed_at' => 'datetime', 'provider_created_at' => 'datetime',
            'install_docker' => 'boolean', 'install_proxy' => 'boolean', 'install_monitoring' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function credential(): HasOne { return $this->hasOne(ServerCredential::class); }
    public function provisioningSteps(): HasMany { return $this->hasMany(ServerProvisioningStep::class)->orderBy('position'); }
    public function metrics(): HasMany { return $this->hasMany(ServerMetric::class); }
    public function containers(): HasMany { return $this->hasMany(DockerContainer::class); }
    public function images(): HasMany { return $this->hasMany(DockerImage::class); }
    public function volumes(): HasMany { return $this->hasMany(DockerVolume::class); }
    public function networks(): HasMany { return $this->hasMany(DockerNetwork::class); }
    public function composeProjects(): HasMany { return $this->hasMany(DockerComposeProject::class); }
    public function applicationDeployments(): HasMany { return $this->hasMany(ApplicationDeployment::class); }
    public function domains(): HasMany { return $this->hasMany(Domain::class); }
    public function providerConnection(): BelongsTo { return $this->belongsTo(ProviderConnection::class); }
    public function managedPlan(): BelongsTo { return $this->belongsTo(ManagedServerPlan::class, 'managed_server_plan_id'); }
    public function infrastructureOperations(): HasMany { return $this->hasMany(InfrastructureOperation::class); }
    public function infrastructureCharges(): HasMany { return $this->hasMany(InfrastructureCharge::class); }
    public function isManaged(): bool { return $this->server_type === 'managed'; }
    public function isByoCloud(): bool
    {
        return $this->server_type === 'byos'
            && filled($this->provider_connection_id)
            && filled($this->provider_resource_id);
    }

    public static function liveIdQuery(int $tenantId): Builder
    {
        return static::query()->where('tenant_id', $tenantId)->select('id');
    }

    public function isFullyProvisioned(): bool
    {
        if ($this->provisioned_at === null || $this->status !== ServerStatus::Online) {
            return false;
        }

        $steps = $this->relationLoaded('provisioningSteps')
            ? $this->provisioningSteps
            : $this->provisioningSteps()->get();

        if ($steps->isEmpty()) {
            return false;
        }

        return $steps->every(fn ($step) => $step->status === 'completed');
    }

    public function isProvisioningIncomplete(): bool
    {
        if (in_array($this->status, [
            ServerStatus::Pending,
            ServerStatus::Testing,
            ServerStatus::Provisioning,
            ServerStatus::Failed,
        ], true)) {
            return true;
        }

        return $this->status === ServerStatus::Online && ! $this->isFullyProvisioned();
    }
}
