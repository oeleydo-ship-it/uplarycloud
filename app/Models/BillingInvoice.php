<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Support\Str;
class BillingInvoice extends Model{protected $fillable=['tenant_id','subscription_id','uuid','number','stripe_invoice_id','status','currency','subtotal','tax','total','hosted_invoice_url','invoice_pdf','paid_at','due_at','line_items'];protected static function booted():void{static::creating(fn($m)=>$m->uuid??=(string)Str::uuid());}protected function casts():array{return['paid_at'=>'datetime','due_at'=>'datetime','line_items'=>'array'];}public function subscription():BelongsTo{return$this->belongsTo(Subscription::class);}public function totalLabel():string{return strtoupper($this->currency).' '.number_format($this->total/100,2);}}
