<?php

namespace App\Http\Requests;

use App\Enums\ServerAuthenticationMethod;
use App\Enums\ServerProvider;
use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Server::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('authorization_method') === 'platform_key') {
            $this->merge([
                'authentication_method' => ServerAuthenticationMethod::SshKey->value,
            ]);
        }
    }

    public function rules(): array
    {
        $usingPlatformKey = $this->input('authorization_method') === 'platform_key';

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('servers')->where('tenant_id', session('tenant_id'))],
            'description' => ['nullable', 'string', 'max:1000'],
            'provider' => ['required', Rule::enum(ServerProvider::class)],
            'server_group' => ['nullable', 'string', 'max:80'],
            'tags' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'location' => ['nullable', 'string', 'max:100'],
            'operating_system' => ['required', Rule::in(config('infrastructure.supported_operating_systems'))],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'ssh_username' => ['required', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/i'],
            'authorization_method' => ['required', Rule::in(['platform_key', 'credentials'])],
            'authentication_method' => ['required', Rule::enum(ServerAuthenticationMethod::class)],
            'private_key' => [
                'nullable',
                Rule::requiredIf(! $usingPlatformKey && $this->input('authentication_method') === 'ssh_key'),
                'string',
                'max:20000',
            ],
            'password' => [
                'nullable',
                Rule::requiredIf(! $usingPlatformKey && $this->input('authentication_method') === 'password'),
                'string',
                'max:1000',
            ],
            'passphrase' => ['nullable', 'string', 'max:1000'],
            'connection_timeout' => ['required', 'integer', 'between:5,120'],
            'install_docker' => ['nullable', 'boolean'],
            'install_proxy' => ['nullable', 'boolean'],
            'install_monitoring' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('authorization_method') === 'platform_key'
                && $this->input('authentication_method') !== ServerAuthenticationMethod::SshKey->value) {
                $validator->errors()->add('authentication_method', 'Platform key authorization requires SSH key authentication.');
            }
        });
    }
}
