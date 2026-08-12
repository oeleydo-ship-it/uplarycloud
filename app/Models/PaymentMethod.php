<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Support\Str;
class PaymentMethod extends Model{protected $fillable=['tenant_id','uuid','stripe_payment_method_id','type','brand','last_four','expiry_month','expiry_year','is_default'];protected static function booted():void{static::creating(fn($m)=>$m->uuid??=(string)Str::uuid());}protected function casts():array{return['is_default'=>'boolean'];}public function getRouteKeyName():string{return'uuid';}}
