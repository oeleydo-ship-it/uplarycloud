<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DockerVolume extends Model
{
    use SoftDeletes;

    protected $fillable = ['tenant_id','server_id','uuid','docker_name','name','driver','mountpoint','size_bytes','status','labels','backed_up_at'];

    protected static function booted(): void { static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid()); }

    protected function casts(): array { return ['labels'=>'array','backed_up_at'=>'datetime']; }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function server(): BelongsTo { return $this->belongsTo(Server::class)->withTrashed(); }

    public function containers(): BelongsToMany
    {
        return $this->belongsToMany(DockerContainer::class, 'container_volume')
            ->withPivot(['mount_path', 'read_only'])
            ->withTimestamps();
    }

    public function isMounted(): bool
    {
        if ($this->relationLoaded('containers')) {
            return $this->containers->isNotEmpty();
        }

        return $this->containers()->exists();
    }

    public function primaryContainer(): ?DockerContainer
    {
        $container = $this->relationLoaded('containers')
            ? $this->containers->first()
            : $this->containers()->with(['deployment.application'])->first();

        if ($container && ! $container->relationLoaded('deployment')) {
            $container->load('deployment.application');
        }

        return $container;
    }

    public function mountPathLabel(): string
    {
        $container = $this->primaryContainer();

        if (! $container) {
            return '—';
        }

        return $container->pivot?->mount_path ?: $this->mountpoint ?: '—';
    }

    public function resolvedApplication(): ?Application
    {
        $container = $this->primaryContainer();

        return $container?->resolvedApplication();
    }

    public function applicationName(): string
    {
        $application = $this->resolvedApplication();

        if ($application) {
            return $application->name;
        }

        $deployment = $this->primaryContainer()?->deployment;

        return $deployment?->name ?? '—';
    }

    public function attachedContainersLabel(): string
    {
        $containers = $this->relationLoaded('containers')
            ? $this->containers
            : $this->containers()->get(['name']);

        return $containers->pluck('name')->filter()->join(', ') ?: 'Not attached';
    }

    public function sizeLabel(): string
    {
        if ($this->size_bytes >= 1073741824) {
            return number_format($this->size_bytes / 1073741824, 1).' GB';
        }

        return number_format($this->size_bytes / 1048576, 1).' MB';
    }

    public function statusLabel(): string
    {
        return $this->isMounted() ? 'Mounted' : 'Available';
    }

    public function statusTone(): string
    {
        return $this->isMounted() ? 'success' : 'warning';
    }

    public function backupLabel(): string
    {
        return $this->backed_up_at?->diffForHumans() ?? 'Never';
    }

    public function usagePercent(): int
    {
        if (! $this->server?->disk_gb || ! $this->size_bytes) {
            return min(100, (int) round($this->size_bytes / 1073741824 * 10));
        }

        return min(100, (int) round(($this->size_bytes / 1073741824) / max(1, $this->server->disk_gb) * 100));
    }
}
