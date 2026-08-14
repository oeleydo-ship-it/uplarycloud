<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BlogPost extends Model
{
    protected $fillable = ['uuid', 'author_id', 'slug', 'title', 'category', 'excerpt', 'body_html', 'keywords', 'focus_keyword', 'meta_title', 'meta_description', 'canonical_url', 'og_image', 'robots_index', 'robots_follow', 'status', 'publish_at', 'published_at', 'read_minutes', 'ai_prompt', 'ai_status', 'ai_error'];

    protected static function booted(): void
    {
        static::creating(fn (BlogPost $post) => $post->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['keywords' => 'array', 'robots_index' => 'boolean', 'robots_follow' => 'boolean', 'publish_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', 'published')->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }
}
