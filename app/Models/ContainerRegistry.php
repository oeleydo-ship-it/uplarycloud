<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Support\Str;
class ContainerRegistry extends Model{protected $fillable=['tenant_id','uuid','name','provider','registry_url','username','token','active','last_verified_at'];protected static function booted():void{static::creating(fn($m)=>$m->uuid??=(string)Str::uuid());}protected function casts():array{return['token'=>'encrypted','active'=>'boolean','last_verified_at'=>'datetime'];}public function getRouteKeyName():string{return'uuid';}}
