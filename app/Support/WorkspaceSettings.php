<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class WorkspaceSettings
{
    public function __construct(private readonly TenantContext $context) {}

    public function all(): array
    {
        $defaults = [
            'platform_url' => config('app.url'),
            'timezone' => config('app.timezone'),
            'language' => config('app.locale'),
            'date_format' => 'M j, Y',
            'time_format' => 'g:i A',
            'maintenance_mode' => false,
        ];
        $stored = Cache::remember('workspace-settings.'.$this->context->id(), 300, fn () => Setting::query()
            ->where('tenant_id', $this->context->id())->where('group', 'general')->pluck('value', 'key')->all());

        return array_merge($defaults, $stored, [
            'maintenance_mode' => filter_var($stored['maintenance_mode'] ?? false, FILTER_VALIDATE_BOOL),
        ]);
    }
}
