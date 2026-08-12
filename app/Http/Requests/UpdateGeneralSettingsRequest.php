<?php

namespace App\Http\Requests;

use App\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->user()?->tenants()->find(session('tenant_id'))?->pivot?->role;
        return $role && MembershipRole::from($role)->canManageWorkspace();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'platform_url' => ['required', 'url:http,https', 'max:255'],
            'timezone' => ['required', Rule::in(['UTC', 'Asia/Dubai', 'Europe/London', 'America/New_York', 'Asia/Singapore'])],
            'language' => ['required', Rule::in(['en', 'ar', 'fr', 'de', 'es'])],
            'date_format' => ['required', Rule::in(['M j, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d'])],
            'time_format' => ['required', Rule::in(['g:i A', 'H:i'])],
            'maintenance_mode' => ['nullable', 'boolean'],
        ];
    }
}
