<?php

namespace App\Models;

use App\Enums\ContainerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DockerContainer extends Model
{
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'server_id', 'application_deployment_id', 'uuid', 'docker_id', 'name', 'image', 'status', 'health', 'ports', 'cpu_percent', 'memory_usage_mb', 'memory_limit_mb', 'restart_count', 'started_at', 'finished_at', 'labels'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['status' => ContainerStatus::class, 'ports' => 'array', 'labels' => 'array', 'cpu_percent' => 'decimal:2', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class)->withTrashed();
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(ApplicationDeployment::class, 'application_deployment_id')->withTrashed();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function volumes(): BelongsToMany
    {
        return $this->belongsToMany(DockerVolume::class, 'container_volume')->withPivot(['mount_path', 'read_only'])->withTimestamps();
    }

    public function networks(): BelongsToMany
    {
        return $this->belongsToMany(DockerNetwork::class, 'container_network')->withPivot('ip_address')->withTimestamps();
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ContainerMetric::class);
    }

    public function resolvedApplication(): ?Application
    {
        if ($this->relationLoaded('deployment') && $this->deployment?->application) {
            return $this->deployment->application;
        }
        if ($this->deployment?->application) {
            return $this->deployment->application;
        }

        $repository = str($this->image)->before(':')->toString();

        return Application::query()
            ->where('docker_image', $repository)
            ->orWhere('docker_image', 'like', '%/'.$repository)
            ->first();
    }

    public function applicationLabel(): string
    {
        $application = $this->resolvedApplication();

        if ($application) {
            return $application->name;
        }

        if ($this->deployment) {
            return $this->deployment->name;
        }

        return 'Custom container';
    }

    public function versionLabel(): ?string
    {
        if ($this->deployment?->docker_tag && $this->deployment->docker_tag !== 'latest') {
            return 'v'.$this->deployment->docker_tag;
        }

        $tag = str($this->image)->after(':')->toString();

        return $tag && $tag !== 'latest' ? 'v'.$tag : null;
    }

    public function uptimeLabel(): string
    {
        if ($this->status === ContainerStatus::Restarting) {
            return 'Restarting';
        }

        if (! in_array($this->status, [ContainerStatus::Running, ContainerStatus::Unhealthy], true)) {
            return $this->status === ContainerStatus::Stopped ? 'Stopped' : '—';
        }

        return $this->started_at?->diffForHumans(null, true, parts: 3) ?? '—';
    }

    public function formattedPorts(): string
    {
        // Only show host-published mappings. Private-only (Dockerfile EXPOSE) is internal
        // and must not look like a shared/conflicting host port in the Containers UI.
        $ports = collect($this->ports ?? [])
            ->map(function (array $port): ?string {
                $public = $port['public'] ?? null;
                $private = $port['private'] ?? null;

                if ($public && $private) {
                    return $public.':'.$private;
                }

                return $public ? (string) $public : null;
            })
            ->filter()
            ->values();

        return $ports->isNotEmpty() ? $ports->join(', ') : 'Internal';
    }

    public function memoryLabel(): string
    {
        $usage = number_format((int) $this->memory_usage_mb).' MB';

        if (! $this->hasMemoryLimit()) {
            return $usage.' / Unlimited';
        }

        $limitMb = (int) $this->memory_limit_mb;
        $limit = $limitMb >= 1024
            ? number_format($limitMb / 1024, 1).' GB'
            : $limitMb.' MB';

        return $usage.' / '.$limit;
    }

    public function hasMemoryLimit(): bool
    {
        return (int) $this->memory_limit_mb > 0;
    }

    public function memoryPercent(): int
    {
        if (! $this->hasMemoryLimit()) {
            return 0;
        }

        return min(100, (int) round(((int) $this->memory_usage_mb) / max(1, (int) $this->memory_limit_mb) * 100));
    }

    public function isOperable(): bool
    {
        return $this->server !== null && ! $this->server->trashed();
    }

    public function canStart(): bool
    {
        return $this->isOperable() && in_array($this->status, [
            ContainerStatus::Stopped,
            ContainerStatus::Exited,
            ContainerStatus::Paused,
            ContainerStatus::Created,
        ], true);
    }

    public function canStop(): bool
    {
        return $this->isOperable() && in_array($this->status, [
            ContainerStatus::Running,
            ContainerStatus::Unhealthy,
            ContainerStatus::Restarting,
        ], true);
    }

    public function canRestart(): bool
    {
        return $this->isOperable() && in_array($this->status, [
            ContainerStatus::Running,
            ContainerStatus::Unhealthy,
            ContainerStatus::Paused,
            ContainerStatus::Stopped,
            ContainerStatus::Exited,
            ContainerStatus::Created,
            ContainerStatus::Restarting,
        ], true);
    }
}
