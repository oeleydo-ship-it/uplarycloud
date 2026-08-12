<?php
namespace App\Jobs;
use App\Models\AlertRule;use App\Services\Operations\AlertEvaluationService;use Illuminate\Contracts\Queue\ShouldQueue;use Illuminate\Foundation\Queue\Queueable;
class EvaluateAlertRulesJob implements ShouldQueue{use Queueable;public function __construct(public ?int $tenantId=null){$this->onQueue(config('infrastructure.queues.monitoring'));}public function handle(AlertEvaluationService $alerts):void{$query=AlertRule::where('enabled',true)->with(['server','container','deployment']);if($this->tenantId)$query->where('tenant_id',$this->tenantId);$query->each(fn($rule)=>$alerts->evaluate($rule));}}
