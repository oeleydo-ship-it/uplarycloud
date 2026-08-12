<?php

namespace App\Models;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DockerImage extends Model
{
    use SoftDeletes;
    protected $fillable = ['tenant_id','server_id','uuid','docker_id','repository','tag','digest','size_bytes','status','used_by_count','pulled_at','update_available','metadata'];
    protected static function booted(): void { static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['pulled_at'=>'datetime','update_available'=>'boolean','metadata'=>'array']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function server(): BelongsTo { return $this->belongsTo(Server::class)->withTrashed(); }

    public function reference(): string
    {
        return $this->repository.':'.$this->tag;
    }

    public function matchingContainers(Collection $containers): Collection
    {
        $reference = $this->normalizedReference($this->reference());

        return $containers
            ->filter(fn (DockerContainer $container) => $container->server_id === $this->server_id
                && $this->normalizedReference($container->image) === $reference)
            ->values();
    }

    public function linkedApplications(): Collection
    {
        return collect($this->getRelation('containers') ?? [])
            ->map(fn (DockerContainer $container) => $container->resolvedApplication())
            ->filter()
            ->unique('id')
            ->values();
    }

    public function linkedVolumes(): Collection
    {
        return collect($this->getRelation('containers') ?? [])
            ->flatMap(fn (DockerContainer $container) => $container->volumes)
            ->unique('id')
            ->values();
    }

    public function usageCount(): int
    {
        return collect($this->getRelation('containers') ?? [])->count();
    }

    public function sizeLabel(): string
    {
        return $this->size_bytes >= 1073741824
            ? number_format($this->size_bytes / 1073741824, 2).' GB'
            : number_format($this->size_bytes / 1048576, 1).' MB';
    }

    private function normalizedReference(string $reference): string
    {
        $reference = strtolower(trim(str($reference)->before('@')->toString()));
        $reference = preg_replace('#^(?:docker\.io/)?library/#', '', $reference) ?? $reference;
        $lastSlash = strrpos($reference, '/');
        $lastColon = strrpos($reference, ':');

        if ($lastColon === false || ($lastSlash !== false && $lastColon < $lastSlash)) {
            $reference .= ':latest';
        }

        return $reference;
    }
}
