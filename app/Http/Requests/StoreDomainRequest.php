<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;use Illuminate\Validation\Rule;
class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool{return $this->user()!==null;}
    protected function prepareForValidation(): void{$this->merge(['hostname'=>strtolower(rtrim(trim((string)$this->hostname),'.')),'redirect_to'=>$this->redirect_to?strtolower(rtrim(trim((string)$this->redirect_to),'.')):null]);}
    public function rules(): array{$pattern='regex:/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';return ['application_deployment_id'=>['required','integer','exists:application_deployments,id'],'hostname'=>['required','string','max:253',$pattern,Rule::unique('domains','hostname')],'redirect_to'=>['nullable','string','max:253',$pattern,'different:hostname'],'force_https'=>['nullable','boolean'],'ssl_enabled'=>['nullable','boolean'],'auto_renew'=>['nullable','boolean']];}
}
