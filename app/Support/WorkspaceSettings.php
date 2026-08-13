<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class WorkspaceSettings
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlatformSettings $platform,
    ) {}

    public function all(): array
    {
        $defaults = [
            'timezone' => $this->platform->get('general', 'default_timezone', config('app.timezone')),
            'language' => $this->platform->get('general', 'default_language', config('app.locale')),
            'date_format' => $this->platform->get('general', 'date_format', 'M j, Y'),
            'time_format' => $this->platform->get('general', 'time_format', 'g:i A'),
        ];

        $stored = Cache::remember('workspace-settings.'.$this->context->id(), 300, fn () => Setting::query()
            ->where('tenant_id', $this->context->id())
            ->where('group', 'general')
            ->whereIn('key', array_keys($defaults))
            ->pluck('value', 'key')
            ->all());

        return array_merge($defaults, $stored);
    }
}
