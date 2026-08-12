<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ContainerMetric extends Model{public $timestamps=false;protected $fillable=['docker_container_id','cpu_percent','memory_usage_mb','network_in_bytes','network_out_bytes','restart_count','health','recorded_at'];protected function casts():array{return['recorded_at'=>'datetime','cpu_percent'=>'decimal:2'];}public function container():BelongsTo{return $this->belongsTo(DockerContainer::class,'docker_container_id');}}
