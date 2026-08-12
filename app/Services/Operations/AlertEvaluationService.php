<?php
namespace App\Services\Operations;
use App\Events\OperationsUpdated;use App\Models\AlertRule;use App\Models\Backup;use App\Models\Domain;
class AlertEvaluationService
{
    public function __construct(private readonly OperationsLogService $logs){}
    public function evaluate(AlertRule $rule):void
    {
        [$triggered,$value,$message]=$this->observation($rule);$open=$rule->incidents()->where('status','open')->first();
        if($triggered&&!$open){$incident=$rule->incidents()->create(['tenant_id'=>$rule->tenant_id,'status'=>'open','severity'=>$rule->severity,'message'=>$message,'observed_value'=>$value,'triggered_at'=>now()]);$rule->update(['last_triggered_at'=>now()]);$this->logs->write($rule->tenant_id,'system',$rule->severity,$message,['server_id'=>$rule->server_id,'source'=>'alerts']);event(new OperationsUpdated($rule->tenant_id,'alerts','triggered',$incident->uuid));}
        elseif(!$triggered&&$open){$open->update(['status'=>'resolved','resolved_at'=>now()]);event(new OperationsUpdated($rule->tenant_id,'alerts','resolved',$open->uuid));}
        $rule->update(['last_evaluated_at'=>now()]);
    }
    private function observation(AlertRule $rule):array
    {
        $threshold=(float)($rule->threshold??0);$value=0;$triggered=false;$label=$rule->name;
        switch($rule->type){case'server_offline':$triggered=$rule->server?->status->value!=='online';$value=$triggered?1:0;break;case'cpu_high':$value=(float)($rule->server?->metrics()->latest('recorded_at')->value('cpu_percent')??0);$triggered=$value>=$threshold;break;case'memory_high':$value=(float)($rule->server?->metrics()->latest('recorded_at')->value('memory_percent')??0);$triggered=$value>=$threshold;break;case'disk_high':$value=(float)($rule->server?->metrics()->latest('recorded_at')->value('disk_percent')??0);$triggered=$value>=$threshold;break;case'container_down':$triggered=$rule->container?->status->value!=='running';$value=$triggered?1:0;break;case'container_restarting':$value=(float)($rule->container?->restart_count??0);$triggered=$value>=$threshold;break;case'health_failed':$triggered=$rule->container?->health==='unhealthy';$value=$triggered?1:0;break;case'deployment_failed':$triggered=$rule->deployment?->status->value==='failed';$value=$triggered?1:0;break;case'backup_failed':$triggered=Backup::where('tenant_id',$rule->tenant_id)->where('status','failed')->where('created_at','>=',now()->subMinutes($rule->duration_minutes))->exists();$value=$triggered?1:0;break;case'ssl_expiring':$expiry=Domain::where('tenant_id',$rule->tenant_id)->whereNotNull('certificate_expires_at')->min('certificate_expires_at');$value=$expiry?(float)now()->diffInDays(\Illuminate\Support\Carbon::parse($expiry)):999;$triggered=$value<=$threshold;break;}$message=$triggered?$label.' triggered'.($rule->metric?' at '.round($value,1):''):$label.' healthy';return[$triggered,$value,$message];
    }
}
