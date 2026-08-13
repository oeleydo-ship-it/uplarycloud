<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_super_admin;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'], 'short_name' => ['required', 'string', 'max:8'],
            'tagline' => ['nullable', 'string', 'max:160'], 'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'company_name' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'max:255'], 'support_email' => ['nullable', 'email', 'max:255'],
            'documentation_url' => ['nullable', 'url', 'max:255'], 'copyright' => ['nullable', 'string', 'max:160'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,ico', 'max:512'],
        ];
    }
}
