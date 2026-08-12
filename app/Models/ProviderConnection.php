<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\HasMany;use Illuminate\Support\Str;
class ProviderConnection extends Model{protected $fillable=['tenant_id','uuid','name','provider','api_token','api_secret','account_id','credentials','active','platform_managed','last_verified_at','last_error'];protected static function booted():void{static::creating(fn($m)=>$m->uuid??=(string)Str::uuid());}protected function casts():array{return['api_token'=>'encrypted','api_secret'=>'encrypted','credentials'=>'encrypted:array','active'=>'boolean','platform_managed'=>'boolean','last_verified_at'=>'datetime'];}public function getRouteKeyName():string{return'uuid';}public function servers():HasMany{return$this->hasMany(Server::class);}}
