<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Support\Str;
class TeamInvitation extends Model{protected $fillable=['tenant_id','invited_by','uuid','email','role','token_hash','status','expires_at','accepted_at'];protected static function booted():void{static::creating(fn($m)=>$m->uuid??=(string)Str::uuid());}protected function casts():array{return['expires_at'=>'datetime','accepted_at'=>'datetime'];}public function getRouteKeyName():string{return'uuid';}public function tenant():BelongsTo{return$this->belongsTo(Tenant::class);}public function inviter():BelongsTo{return$this->belongsTo(User::class,'invited_by');}}
