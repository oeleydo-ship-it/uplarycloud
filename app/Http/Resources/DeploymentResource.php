<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;use Illuminate\Http\Resources\Json\JsonResource;
class DeploymentResource extends JsonResource{public function toArray(Request $request):array{return['id'=>$this->uuid,'name'=>$this->name,'type'=>$this->deployment_type,'framework'=>$this->framework,'image'=>$this->docker_image.':'.$this->docker_tag,'domain'=>$this->domain,'status'=>$this->status->value??$this->status,'progress'=>$this->progress,'deployed_at'=>$this->deployed_at,'server'=>new ServerResource($this->whenLoaded('server'))];}}
