<?php
namespace App\Services\Billing;
use App\Models\Tenant;use App\Models\UsageRecord;
class UsageService
{
    public function collect(Tenant $tenant):array
    {
        $start=now()->startOfHour();$end=$start->copy()->endOfHour();$metrics=['servers'=>[$tenant->servers()->count(),'count'],'team_members'=>[$tenant->users()->wherePivot('is_active',true)->count(),'count'],'backup_storage_gb'=>[round($tenant->backups()->where('status','successful')->sum('size_bytes')/1073741824,4),'GB'],'bandwidth_gb'=>[round((float)\DB::table('server_metrics')->join('servers','servers.id','=','server_metrics.server_id')->where('servers.tenant_id',$tenant->id)->whereBetween('recorded_at',[now()->startOfMonth(),now()])->sum(\DB::raw('network_in_bytes + network_out_bytes'))/1073741824,4),'GB'],'managed_servers'=>[$tenant->servers()->where('server_type','managed')->count(),'count']];
        foreach($metrics as $metric=>[$quantity,$unit])UsageRecord::updateOrCreate(['tenant_id'=>$tenant->id,'metric'=>$metric,'period_starts_at'=>$start],['quantity'=>$quantity,'unit'=>$unit,'period_ends_at'=>$end]);return$metrics;
    }
    public function latest(Tenant $tenant):array{$records=$tenant->usageRecords()->latest('period_ends_at')->get()->unique('metric')->keyBy('metric');if($records->isEmpty()){$this->collect($tenant);$records=$tenant->usageRecords()->latest('period_ends_at')->get()->unique('metric')->keyBy('metric');}return$records->map(fn($r)=>(float)$r->quantity)->all();}
}
