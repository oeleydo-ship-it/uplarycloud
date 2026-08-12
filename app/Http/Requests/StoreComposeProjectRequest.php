<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComposeProjectRequest extends FormRequest
{
    public function authorize(): bool { $server=Server::where('tenant_id',session('tenant_id'))->find($this->integer('server_id')); return $server && $this->user()->can('operate',$server); }
    public function rules(): array { return ['name'=>['required','string','max:120'],'server_id'=>['required',Rule::exists('servers','id')->where('tenant_id',session('tenant_id'))],'compose_content'=>['required','string','max:500000'],'environment'=>['nullable','string','max:100000']]; }
}
