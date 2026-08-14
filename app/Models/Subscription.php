<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable=['tenant_id','plan_id','status','billing_cycle','stripe_customer_id','stripe_subscription_id','trial_ends_at','current_period_starts_at','current_period_ends_at','cancel_at','ended_at','metadata'];
    protected function casts():array{return['trial_ends_at'=>'datetime','current_period_starts_at'=>'datetime','current_period_ends_at'=>'datetime','cancel_at'=>'datetime','ended_at'=>'datetime','metadata'=>'array'];}
    public function tenant():BelongsTo{return $this->belongsTo(Tenant::class);}public function plan():BelongsTo{return $this->belongsTo(Plan::class);}public function invoices():HasMany{return $this->hasMany(BillingInvoice::class);}
    public function active(): bool
    {
        if (! in_array($this->status, ['active', 'trialing'], true)) {
            return false;
        }

        if ($this->ended_at?->isPast() || $this->current_period_ends_at?->isPast()) {
            return false;
        }

        return $this->status !== 'trialing' || ! $this->trial_ends_at || $this->trial_ends_at->isFuture();
    }
}
