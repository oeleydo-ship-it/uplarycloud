<?php
namespace App\Services\Billing;
use App\Contracts\Billing\BillingGatewayInterface;use App\Models\Plan;use App\Models\Tenant;use RuntimeException;use Stripe\StripeClient;
class StripeBillingGateway implements BillingGatewayInterface
{
    private StripeClient $stripe;
    public function __construct(){if(!config('billing.stripe.secret'))throw new RuntimeException('Stripe is not configured.');$this->stripe=new StripeClient(config('billing.stripe.secret'));}
    public function checkout(Tenant $tenant,Plan $plan,string $cycle,string $successUrl,string $cancelUrl):?string
    {
        $price=$cycle==='yearly'?$plan->stripe_yearly_price_id:$plan->stripe_monthly_price_id;if(!$price)throw new RuntimeException('This plan does not have a Stripe price configured.');
        $subscription=$tenant->currentSubscription();$customer=$subscription?->stripe_customer_id;
        if(!$customer){$owner=$tenant->users()->wherePivot('role','owner')->first();$customer=$this->stripe->customers->create(['name'=>$tenant->name,'email'=>$owner?->email,'metadata'=>['tenant_id'=>(string)$tenant->id]])->id;}
        $session=$this->stripe->checkout->sessions->create(['mode'=>'subscription','customer'=>$customer,'line_items'=>[['price'=>$price,'quantity'=>1]],'success_url'=>$successUrl,'cancel_url'=>$cancelUrl,'allow_promotion_codes'=>true,'metadata'=>['tenant_id'=>(string)$tenant->id,'plan_id'=>(string)$plan->id,'billing_cycle'=>$cycle],'subscription_data'=>['metadata'=>['tenant_id'=>(string)$tenant->id,'plan_id'=>(string)$plan->id,'billing_cycle'=>$cycle]]]);
        return $session->url;
    }
    public function portal(Tenant $tenant,string $returnUrl):?string{$customer=$tenant->currentSubscription()?->stripe_customer_id;if(!$customer)throw new RuntimeException('No Stripe customer is connected.');return$this->stripe->billingPortal->sessions->create(['customer'=>$customer,'return_url'=>$returnUrl])->url;}
    public function cancel(Tenant $tenant):void{$subscription=$tenant->currentSubscription();if(!$subscription?->stripe_subscription_id)throw new RuntimeException('No Stripe subscription is connected.');$this->stripe->subscriptions->update($subscription->stripe_subscription_id,['cancel_at_period_end'=>true]);}
}
