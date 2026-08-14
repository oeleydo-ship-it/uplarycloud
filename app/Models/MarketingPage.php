<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketingPage extends Model
{
    public const CORE_SLUGS = ['home', 'features', 'pricing', 'use-cases', 'about', 'contact'];

    protected $fillable = [
        'uuid', 'slug', 'title', 'nav_label', 'hero_kicker', 'hero_title', 'hero_description',
        'body_html', 'meta_title', 'meta_description', 'canonical_url', 'og_image',
        'robots_index', 'robots_follow', 'published', 'show_in_nav', 'position',
    ];

    protected static function booted(): void
    {
        static::creating(fn (MarketingPage $page) => $page->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['robots_index' => 'boolean', 'robots_follow' => 'boolean', 'published' => 'boolean', 'show_in_nav' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isCore(): bool
    {
        return in_array($this->slug, self::CORE_SLUGS, true);
    }

    public function robots(): string
    {
        return ($this->robots_index ? 'index' : 'noindex').','.($this->robots_follow ? 'follow' : 'nofollow');
    }
}
