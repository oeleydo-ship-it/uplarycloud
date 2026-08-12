<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Support\Str;
class AlertIncident extends Model{protected $fillable=['tenant_id','alert_rule_id','uuid','status','severity','message','observed_value','triggered_at','acknowledged_at','resolved_at'];protected static function booted():void{static::creating(fn($m)=>$m->uuid??=(string)Str::uuid());}protected function casts():array{return['triggered_at'=>'datetime','acknowledged_at'=>'datetime','resolved_at'=>'datetime','observed_value'=>'decimal:2'];}public function getRouteKeyName():string{return'uuid';}public function rule():BelongsTo{return $this->belongsTo(AlertRule::class,'alert_rule_id');}}
