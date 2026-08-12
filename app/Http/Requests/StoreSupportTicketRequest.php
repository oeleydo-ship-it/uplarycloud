<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:180'],
            'category' => ['required', Rule::in(['deployment', 'server', 'billing', 'domain', 'backup', 'account', 'other'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')->where('tenant_id', session('tenant_id'))],
            'application_deployment_id' => ['nullable', 'integer', Rule::exists('application_deployments', 'id')->where('tenant_id', session('tenant_id'))],
        ];
    }
}
