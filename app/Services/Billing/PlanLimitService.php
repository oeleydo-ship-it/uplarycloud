<?php
namespace App\Services\Billing;
use App\Models\Plan;use App\Models\Tenant;use Illuminate\Validation\ValidationException;
class PlanLimitService
{
    public function plan(Tenant $tenant):Plan{return$tenant->currentSubscription()?->plan??Plan::firstOrCreate(['slug'=>'free'],['name'=>'Free','description'=>'Core platform access','monthly_price'=>0,'yearly_price'=>0,'currency'=>'USD','limits'=>['servers'=>1,'team_members'=>1,'backup_storage_gb'=>1,'managed_servers'=>0],'features'=>['Core Docker management'],'active'=>true]);}
    public function usage(Tenant $tenant,string $metric):float{return match($metric){'servers'=>(float)$tenant->servers()->count(),'team_members'=>(float)($tenant->users()->wherePivot('is_active',true)->count()+$tenant->invitations()->where('status','pending')->where('expires_at','>',now())->count()),'backup_storage_gb'=>(float)$tenant->backups()->sum('size_bytes')/1073741824,'managed_servers'=>(float)$tenant->servers()->where('server_type','managed')->count(),default=>0};}
    public function allows(Tenant $tenant,string $metric,float $additional=1):bool{$limit=$this->plan($tenant)->limit($metric);return$limit===null||$this->usage($tenant,$metric)+$additional<=$limit;}
    public function enforce(Tenant $tenant,string $metric,float $additional=1):void{if(!$this->allows($tenant,$metric,$additional))throw ValidationException::withMessages([$metric=>'Your current plan limit has been reached. Upgrade to continue.']);}
}
