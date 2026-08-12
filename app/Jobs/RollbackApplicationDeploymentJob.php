<?php

namespace App\Jobs;

use App\Events\DeploymentProgressed;
use App\Models\ActivityLog;
use App\Models\ApplicationDeployment;
use App\Models\DeploymentRelease;
use App\Services\Deployments\DeploymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RollbackApplicationDeploymentJob implements ShouldQueue
{
    use Queueable;
    public int $tries=2; public int $timeout=600;
    public function __construct(public int $deploymentId, public int $releaseId, public int $tenantId, public ?int $userId=null) { $this->onQueue(config('infrastructure.queues.deployments')); }
    public function handle(DeploymentService $service): void
    {
        $deployment=ApplicationDeployment::where('tenant_id',$this->tenantId)->findOrFail($this->deploymentId); $release=DeploymentRelease::where('application_deployment_id',$deployment->id)->findOrFail($this->releaseId);
        try { $service->rollback($deployment,$release); ActivityLog::create(['tenant_id'=>$this->tenantId,'user_id'=>$this->userId,'action'=>'deployment.rollback','description'=>$deployment->name.' rolled back to '.$release->version,'subject_type'=>ApplicationDeployment::class,'subject_id'=>$deployment->id]); event(new DeploymentProgressed($this->tenantId,$deployment->uuid,'running',100,'complete')); }
        catch (Throwable $e) { $deployment->update(['status'=>'failed','last_error'=>$e->getMessage()]); $service->log($deployment,'error','Rollback failed: '.$e->getMessage()); event(new DeploymentProgressed($this->tenantId,$deployment->uuid,'failed',$deployment->progress,'rollback')); }
    }
}
