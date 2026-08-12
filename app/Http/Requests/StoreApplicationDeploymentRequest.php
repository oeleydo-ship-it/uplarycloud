<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationDeploymentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array { return ['application_id'=>['nullable','exists:applications,id'],'deployment_type'=>['required',Rule::in(['marketplace','custom'])],'server_id'=>['required','integer','exists:servers,id'],'name'=>['required','string','max:100'],'description'=>['nullable','string','max:1000'],'docker_image'=>['required','regex:/^[a-zA-Z0-9._\/-]+$/','max:255'],'docker_tag'=>['required','regex:/^[a-zA-Z0-9._-]+$/','max:100'],'container_port'=>['nullable','integer','between:1,65535'],'domain'=>['nullable','string','max:253','regex:/^(?=.{1,253}$)([a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/'],'cpu_limit'=>['nullable','numeric','between:0.1,64'],'memory_limit_mb'=>['nullable','integer','between:128,1048576'],'disk_limit_gb'=>['nullable','integer','between:1,100000'],'restart_policy'=>['required',Rule::in(['no','always','unless-stopped','on-failure'])],'auto_start'=>['nullable','boolean'],'backup_enabled'=>['nullable','boolean'],'environment_keys'=>['nullable','array','max:50'],'environment_keys.*'=>['nullable','string','regex:/^[A-Z_][A-Z0-9_]*$/','max:100'],'environment_values'=>['nullable','array'],'environment_values.*'=>['nullable','string','max:10000'],'environment_descriptions'=>['nullable','array'],'environment_descriptions.*'=>['nullable','string','max:255'],'environment_secrets'=>['nullable','array']]; }
}
