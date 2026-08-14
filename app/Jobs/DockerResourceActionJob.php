<?php

namespace App\Jobs;

use App\Events\DockerResourceUpdated;
use App\Models\ActivityLog;
use App\Models\DockerComposeProject;
use App\Models\DockerContainer;
use App\Models\DockerImage;
use App\Models\DockerNetwork;
use App\Models\DockerVolume;
use App\Services\Docker\DockerService;
use App\Services\Docker\ContainerInventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DockerResourceActionJob implements ShouldQueue
{
    use Queueable;
    public int $tries=3; public int $timeout=300; public array $backoff=[5,20,60];
    public function __construct(public string $type, public int $id, public string $action, public int $tenantId, public ?int $userId=null) { $this->onQueue(config('infrastructure.queues.deployments')); }
    public function handle(DockerService $docker, ContainerInventoryService $inventory): void
    {
        $model = $this->model();
        match ($this->type) {
            'container' => $this->action === 'inspect'
                ? $inventory->refreshOne($model)
                : $docker->container($model, $this->action),
            'image' => $this->action === 'pull' ? $docker->pull($model) : $docker->removeImage($model),
            'volume' => $docker->removeVolume($model), 'network' => $docker->removeNetwork($model),
            'compose' => $docker->deployCompose($model), default => throw new \RuntimeException('Unknown Docker resource type.'),
        };
        ActivityLog::create(['tenant_id'=>$this->tenantId,'user_id'=>$this->userId,'action'=>'docker.'.$this->type.'.'.$this->action,'description'=>ucfirst($this->type).' '.$this->action.' completed','subject_type'=>$model::class,'subject_id'=>$model->id]);
        event(new DockerResourceUpdated($this->tenantId,$this->type,$model->uuid,$this->action,'completed'));
    }
    public function failed(Throwable $e): void { event(new DockerResourceUpdated($this->tenantId,$this->type,(string)$this->id,$this->action,'failed')); }
    private function model(): object { $class=match($this->type){'container'=>DockerContainer::class,'image'=>DockerImage::class,'volume'=>DockerVolume::class,'network'=>DockerNetwork::class,'compose'=>DockerComposeProject::class,default=>throw new \RuntimeException('Unknown resource.')}; return $class::where('tenant_id',$this->tenantId)->findOrFail($this->id); }
}
