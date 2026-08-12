<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UsageRecord extends Model{protected $fillable=['tenant_id','metric','quantity','unit','period_starts_at','period_ends_at','metadata'];protected function casts():array{return['quantity'=>'decimal:4','period_starts_at'=>'datetime','period_ends_at'=>'datetime','metadata'=>'array'];}}
