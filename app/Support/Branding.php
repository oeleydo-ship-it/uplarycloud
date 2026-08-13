<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class Branding
{
    public const CACHE_KEY = 'branding.platform';

    public const COLOR_KEYS = ['primary_color', 'secondary_color'];

    public function __construct(private readonly TenantContext $context) {}

    public function all(): array
    {
        return array_merge($this->platform(), $this->tenantColorOverrides());
    }

    public function platform(): array
    {
        $defaults = [
            'name' => config('app.name'), 'short_name' => 'UP',
            'tagline' => 'Deploy confidently. Operate clearly.',
            'primary_color' => '#6C4CF5', 'secondary_color' => '#17152B',
            'company_name' => config('app.name'), 'website' => '',
            'support_email' => '', 'documentation_url' => '',
            'copyright' => 'All rights reserved.', 'logo' => '', 'favicon' => '',
        ];

        $stored = Cache::remember(self::CACHE_KEY, 300, fn () => Setting::query()
            ->whereNull('tenant_id')
            ->where('group', 'branding')
            ->pluck('value', 'key')->all());

        return array_merge($defaults, $stored);
    }

    public function tenantColorOverrides(): array
    {
        if (! $this->context->has()) {
            return [];
        }

        $tenantId = $this->context->id();

        return Cache::remember("console-theme.{$tenantId}", 300, function () use ($tenantId): array {
            $legacy = Setting::query()
                ->where('tenant_id', $tenantId)
                ->where('group', 'branding')
                ->whereIn('key', self::COLOR_KEYS)
                ->pluck('value', 'key')->all();

            $theme = Setting::query()
                ->where('tenant_id', $tenantId)
                ->where('group', 'theme')
                ->whereIn('key', self::COLOR_KEYS)
                ->pluck('value', 'key')->all();

            return collect(array_merge($legacy, $theme))
                ->only(self::COLOR_KEYS)
                ->filter(fn (mixed $value) => filled($value))
                ->all();
        });
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->all()[$key] ?? $fallback;
    }

    public function name(): string
    {
        return $this->get('name', config('app.name'));
    }
}
