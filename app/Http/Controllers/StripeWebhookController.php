<?php
namespace App\Http\Controllers;
use App\Models\BillingInvoice;use App\Models\Plan;use App\Models\Subscription;use App\Models\Tenant;use Illuminate\Http\Request;use Illuminate\Http\Response;use Stripe\Webhook;
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request):Response
    {
        abort_unless(config('billing.stripe.webhook_secret'),503,'Stripe webhook is not configured.');
        try{$event=Webhook::constructEvent($request->getContent(),(string)$request->header('Stripe-Signature'),config('billing.stripe.webhook_secret'));}catch(\Throwable){abort(400,'Invalid Stripe webhook signature.');}
        $object=$event->data->object;
        match($event->type){'checkout.session.completed'=>$this->checkout($object),'customer.subscription.updated','customer.subscription.deleted'=>$this->subscription($object),'invoice.paid','invoice.payment_failed'=>$this->invoice($object),default=>null};
        return response('accepted');
    }
    private function checkout(object $session):void{$tenant=Tenant::find($session->metadata->tenant_id??null);$plan=Plan::find($session->metadata->plan_id??null);if(!$tenant||!$plan)return;$tenant->subscriptions()->whereIn('status',['active','trialing','past_due'])->update(['status'=>'canceled','ended_at'=>now()]);Subscription::updateOrCreate(['stripe_subscription_id'=>$session->subscription],['tenant_id'=>$tenant->id,'plan_id'=>$plan->id,'status'=>'active','billing_cycle'=>$session->metadata->billing_cycle??'monthly','stripe_customer_id'=>$session->customer,'current_period_starts_at'=>now()]);}
    private function subscription(object $stripe):void{$subscription=Subscription::where('stripe_subscription_id',$stripe->id)->first();if(!$subscription)return;$subscription->update(['status'=>$stripe->status,'current_period_starts_at'=>isset($stripe->current_period_start)?\Carbon\Carbon::createFromTimestamp($stripe->current_period_start):$subscription->current_period_starts_at,'current_period_ends_at'=>isset($stripe->current_period_end)?\Carbon\Carbon::createFromTimestamp($stripe->current_period_end):null,'cancel_at'=>isset($stripe->cancel_at)&&$stripe->cancel_at?\Carbon\Carbon::createFromTimestamp($stripe->cancel_at):null,'ended_at'=>isset($stripe->ended_at)&&$stripe->ended_at?\Carbon\Carbon::createFromTimestamp($stripe->ended_at):null]);}
    private function invoice(object $stripe):void{$subscription=Subscription::where('stripe_subscription_id',$stripe->subscription??null)->first();if(!$subscription)return;BillingInvoice::updateOrCreate(['stripe_invoice_id'=>$stripe->id],['tenant_id'=>$subscription->tenant_id,'subscription_id'=>$subscription->id,'number'=>$stripe->number,'status'=>$stripe->status,'currency'=>strtoupper($stripe->currency),'subtotal'=>$stripe->subtotal,'tax'=>$stripe->tax??0,'total'=>$stripe->total,'hosted_invoice_url'=>$stripe->hosted_invoice_url,'invoice_pdf'=>$stripe->invoice_pdf,'paid_at'=>$stripe->status==='paid'?now():null,'due_at'=>isset($stripe->due_date)&&$stripe->due_date?\Carbon\Carbon::createFromTimestamp($stripe->due_date):null]);if($stripe->status!=='paid')$subscription->update(['status'=>'past_due']);}
}
