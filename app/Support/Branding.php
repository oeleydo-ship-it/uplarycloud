<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class Branding
{
    public function __construct(private readonly TenantContext $context) {}

    public function all(): array
    {
        $defaults = [
            'name' => config('app.name'), 'short_name' => 'UP',
            'tagline' => 'Deploy confidently. Operate clearly.',
            'primary_color' => '#6C4CF5', 'secondary_color' => '#17152B',
            'company_name' => config('app.name'), 'website' => '',
            'support_email' => '', 'documentation_url' => '',
            'copyright' => 'All rights reserved.', 'logo' => '', 'favicon' => '',
        ];

        if (! auth()->check()) {
            return $defaults;
        }

        $tenantId = $this->context->id();
        $stored = Cache::remember("branding.{$tenantId}", 300, fn () => Setting::query()
            ->where('tenant_id', $tenantId)
            ->where('group', 'branding')
            ->pluck('value', 'key')->all());

        return array_merge($defaults, $stored);
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
