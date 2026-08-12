<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;use Illuminate\Http\Resources\Json\JsonResource;
class ServerResource extends JsonResource{public function toArray(Request $request):array{return['id'=>$this->uuid,'name'=>$this->name,'provider'=>$this->provider->value??$this->provider,'ip_address'=>$this->ip_address,'status'=>$this->status->value??$this->status,'cpu_cores'=>$this->cpu_cores,'memory_mb'=>$this->memory_mb,'disk_gb'=>$this->disk_gb,'last_seen_at'=>$this->last_seen_at];}}
