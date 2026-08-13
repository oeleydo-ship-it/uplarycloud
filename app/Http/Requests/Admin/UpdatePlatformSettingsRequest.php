<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_super_admin;
    }

    public function rules(): array
    {
        return [
            'platform_name' => ['required', 'string', 'max:80'],
            'platform_url' => ['required', 'url', 'max:255'],
            'support_email' => ['required', 'email'],
            'default_timezone' => ['required', Rule::in(['UTC', 'Asia/Dubai', 'Europe/London', 'America/New_York', 'Asia/Singapore'])],
            'default_currency' => ['required', 'string', 'size:3'],
            'default_language' => ['nullable', Rule::in(['en', 'ar', 'fr', 'de', 'es'])],
            'date_format' => ['nullable', Rule::in(['M j, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d'])],
            'time_format' => ['nullable', Rule::in(['g:i A', 'H:i'])],
            'registration_enabled' => ['nullable', 'boolean'],
            'email_verification' => ['nullable', 'boolean'],
            'maintenance_mode' => ['nullable', 'boolean'],
            'read_only_mode' => ['nullable', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
            'managed_servers_enabled' => ['nullable', 'boolean'],
            'marketplace_enabled' => ['nullable', 'boolean'],
            'git_deployments_enabled' => ['nullable', 'boolean'],
            'custom_docker_enabled' => ['nullable', 'boolean'],
            'monitoring_enabled' => ['nullable', 'boolean'],
            'alerts_enabled' => ['nullable', 'boolean'],
            'backups_enabled' => ['nullable', 'boolean'],
            'api_tokens_enabled' => ['nullable', 'boolean'],
            'support_enabled' => ['nullable', 'boolean'],
        ];
    }

    public function platformSettings(): array
    {
        $data = $this->safe()->except([
            'registration_enabled',
            'email_verification',
            'maintenance_mode',
            'read_only_mode',
            'managed_servers_enabled',
            'marketplace_enabled',
            'git_deployments_enabled',
            'custom_docker_enabled',
            'monitoring_enabled',
            'alerts_enabled',
            'backups_enabled',
            'api_tokens_enabled',
            'support_enabled',
        ]);

        foreach (['registration_enabled', 'email_verification', 'maintenance_mode', 'read_only_mode', 'managed_servers_enabled', 'marketplace_enabled', 'git_deployments_enabled', 'custom_docker_enabled', 'monitoring_enabled', 'alerts_enabled', 'backups_enabled', 'api_tokens_enabled', 'support_enabled'] as $key) {
            $data[$key] = $this->boolean($key);
        }

        return collect($data)->reject(fn (mixed $value) => $value === null)->all();
    }
}
