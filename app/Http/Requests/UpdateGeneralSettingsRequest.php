<?php

namespace App\Http\Requests;

use App\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGeneralSettingsRequest extends FormRequest
{
    public const WORKSPACE_KEYS = ['timezone', 'language', 'date_format', 'time_format'];

    public const COLOR_KEYS = ['primary_color', 'secondary_color'];

    public function authorize(): bool
    {
        $role = $this->user()?->tenants()->find(session('tenant_id'))?->pivot?->role;

        return $role && MembershipRole::from($role)->canManageWorkspace();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'timezone' => ['required', Rule::in(['UTC', 'Asia/Dubai', 'Europe/London', 'America/New_York', 'Asia/Singapore'])],
            'language' => ['required', Rule::in(['en', 'ar', 'fr', 'de', 'es'])],
            'date_format' => ['required', Rule::in(['M j, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d'])],
            'time_format' => ['required', Rule::in(['g:i A', 'H:i'])],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function workspaceSettings(): array
    {
        return $this->safe()->only(self::WORKSPACE_KEYS);
    }

    public function consoleColors(): array
    {
        return collect($this->safe()->only(self::COLOR_KEYS))
            ->filter(fn (mixed $value) => filled($value))
            ->all();
    }
}
