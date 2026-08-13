<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'company' => ['nullable', 'string', 'max:160'],
            'topic' => ['required', Rule::in(['general', 'sales', 'support', 'partnership'])],
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'min:12', 'max:5000'],
        ];
    }
}
