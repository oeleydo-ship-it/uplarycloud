<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['build_pack_id' => ['required', 'exists:build_packs,id'], 'server_id' => ['required', 'exists:servers,id'], 'name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:1000'], 'repository_url' => ['required', 'string', 'max:500'], 'git_provider' => ['required', Rule::in(['github', 'gitlab', 'bitbucket'])], 'branch' => ['required', 'regex:/^[A-Za-z0-9._\/-]+$/', 'max:150'], 'deploy_key' => ['nullable', 'string', 'max:20000'], 'runtime_version' => ['required', 'regex:/^[0-9]+(?:\.[0-9]+)?$/', 'max:10'], 'root_directory' => ['required', 'regex:#^/(?!.*\.\.)[A-Za-z0-9._\/-]*$#', 'max:255'], 'package_manager' => ['nullable', Rule::in(['npm', 'pnpm', 'yarn', 'bun', 'composer'])], 'install_command' => ['nullable', 'string', 'max:255'], 'build_command' => ['nullable', 'string', 'max:255'], 'start_command' => ['nullable', 'string', 'max:255'], 'output_directory' => ['nullable', 'regex:#^(?!/)(?!.*\.\.)[A-Za-z0-9._\/-]+$#', 'max:100'], 'container_port' => ['required', 'integer', 'between:1,65535'], 'domain' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/'], 'database_engine' => ['nullable', Rule::in(['mysql', 'postgresql'])], 'enable_redis' => ['nullable', 'boolean'], 'enable_queue' => ['nullable', 'boolean'], 'enable_scheduler' => ['nullable', 'boolean'], 'enable_reverb' => ['nullable', 'boolean'], 'enable_horizon' => ['nullable', 'boolean'], 'auto_deploy' => ['nullable', 'boolean'], 'cpu_limit' => ['nullable', 'numeric', 'between:0.1,64'], 'memory_limit_mb' => ['nullable', 'integer', 'between:128,1048576'], 'disk_limit_gb' => ['nullable', 'integer', 'between:1,100000'], 'environment_keys' => ['nullable', 'array', 'max:50'], 'environment_keys.*' => ['nullable', 'regex:/^[A-Z_][A-Z0-9_]*$/', 'max:100'], 'environment_values' => ['nullable', 'array'], 'environment_values.*' => ['nullable', 'string', 'max:10000'], 'environment_secrets' => ['nullable', 'array']];
    }
}
