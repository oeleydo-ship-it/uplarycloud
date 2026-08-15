<x-dashboard-layout title="Billing">
<div class="commercial-page billing-page" x-data="{cycle:'{{ $subscription?->billing_cycle ?? 'monthly' }}',tab:'overview'}">
    <div class="page-heading billing-heading">
        <div>
            <p class="breadcrumb">Workspace / Billing</p>
            <h1>Billing & subscription</h1>
            <p>Manage your plan, payment details, invoices, and measured platform usage.</p>
        </div>
        <div class="heading-actions">
            @if($subscription)
                <form method="POST" action="{{ route('billing.portal') }}">
                    @csrf
                    <button class="button button--secondary"><i data-lucide="external-link"></i> Customer portal</button>
                </form>
            @endif
        </div>
    </div>

    @error('billing')
        <div class="commercial-notice commercial-notice--error"><i data-lucide="circle-alert"></i>{{ $message }}</div>
    @enderror
    @if(request('checkout') === 'success')
        <div class="commercial-notice commercial-notice--success"><i data-lucide="circle-check"></i>Payment received. Your subscription or server order will activate shortly once Stripe confirms the payment.</div>
    @elseif(request('checkout') === 'canceled')
        <div class="commercial-notice commercial-notice--warning"><i data-lucide="circle-alert"></i>Checkout was canceled. No payment was taken.</div>
    @endif

    <nav class="commercial-tabs billing-tabs" aria-label="Billing sections">
        <button type="button" :class="tab==='overview'&&'is-active'" @click="tab='overview'">Overview</button>
        <button type="button" :class="tab==='invoices'&&'is-active'" @click="tab='invoices'">Invoices</button>
        <button type="button" :class="tab==='infrastructure'&&'is-active'" @click="tab='infrastructure'">Infrastructure</button>
        <button type="button" :class="tab==='payment'&&'is-active'" @click="tab='payment'">Payment methods</button>
        <button type="button" :class="tab==='usage'&&'is-active'" @click="tab='usage'">Usage</button>
    </nav>

    <div class="billing-panel" x-show="tab==='overview'">
        <section class="billing-hero card">
            <div class="billing-hero-copy">
                <span class="eyebrow">Current plan</span>
                <div class="billing-plan-name">
                    <h2>{{ $subscription?->plan->name ?? 'Free' }}</h2>
                    <em class="status status--success"><i></i>{{ ucfirst($subscription?->status ?? 'active') }}</em>
                </div>
                <p>{{ $subscription?->plan->description ?? 'Core platform access for getting started.' }}</p>
                <div class="billing-meta">
                    <span>
                        <small>Billing cycle</small>
                        <strong>{{ ucfirst($subscription?->billing_cycle ?? 'No charge') }}</strong>
                    </span>
                    <span>
                        <small>Next payment</small>
                        <strong>{{ $subscription?->current_period_ends_at?->format('M d, Y') ?? '—' }}</strong>
                    </span>
                    <span>
                        <small>Payment method</small>
                        <strong>{{ ($paymentMethods->first()?->brand ?? 'No card') }} {{ $paymentMethods->first()?->last_four ? '•••• '.$paymentMethods->first()->last_four : '' }}</strong>
                    </span>
                </div>
            </div>
            <div class="current-price">
                <strong>${{ number_format(($subscription?->plan->price($subscription->billing_cycle) ?? 0)/100) }}</strong>
                <span>/ {{ $subscription?->billing_cycle === 'yearly' ? 'year' : 'month' }}</span>
            </div>
        </section>

        <div class="cycle-switch" role="group" aria-label="Billing cycle">
            <span :class="cycle==='monthly'&&'is-active'">Monthly</span>
            <button type="button" @click="cycle=cycle==='monthly'?'yearly':'monthly'" :class="cycle==='yearly'&&'is-yearly'" :aria-pressed="cycle==='yearly'">
                <i></i>
            </button>
            <span :class="cycle==='yearly'&&'is-active'">Yearly <em>Save up to 20%</em></span>
        </div>

        <section class="pricing-grid">
            @foreach($plans as $plan)
                <article class="price-card card {{ $plan->featured?'price-card--featured':'' }} {{ $subscription?->plan_id===$plan->id?'price-card--current':'' }}">
                    @if($plan->featured)<span class="popular-label">Most popular</span>@endif
                    <div class="price-head">
                        <span class="price-icon"><i data-lucide="{{ $plan->slug==='free'?'sprout':($plan->slug==='business'?'building-2':'zap') }}"></i></span>
                        <h3>{{ $plan->name }}</h3>
                        <p>{{ $plan->description }}</p>
                    </div>
                    <div class="price">
                        <strong x-text="'$'+(cycle==='yearly'?{{ $plan->yearly_price }}:{{ $plan->monthly_price }})/100"></strong>
                        <span x-text="cycle==='yearly'?'/ year':'/ month'"></span>
                    </div>
                    <ul>
                        @foreach($plan->features??[] as $feature)
                            <li><i data-lucide="check"></i>{{ $feature }}</li>
                        @endforeach
                        <li><i data-lucide="server"></i>{{ $plan->limit('servers') ?? 'Unlimited' }} servers</li>
                        <li><i data-lucide="blocks"></i>{{ $plan->limit('applications') ?? 'Unlimited' }} applications</li>
                        <li><i data-lucide="globe-2"></i>{{ $plan->limit('domains') ?? 'Unlimited' }} domains</li>
                        <li><i data-lucide="users"></i>{{ $plan->limit('team_members') ?? 'Unlimited' }} team members</li>
                        <li><i data-lucide="archive"></i>{{ $plan->limit('backup_storage_gb') ?? 'Unlimited' }} GB backup storage</li>
                    </ul>
                    <form method="POST" action="{{ route('billing.subscribe') }}">
                        @csrf
                        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                        <input type="hidden" name="billing_cycle" :value="cycle">
                        @php
                            $planPrice = $plan->monthly_price;
                            $requiresPayment = $billingConfig->requiresPaymentGateway() && $planPrice > 0;
                            $stripePriceMissing = $requiresPayment && !($plan->stripe_monthly_price_id && $plan->stripe_yearly_price_id);
                            $isCurrent = $subscription?->plan_id === $plan->id;
                        @endphp
                        <button
                            class="button {{ $isCurrent ? 'button--secondary' : 'button--primary' }} button--full"
                            @disabled($isCurrent || $stripePriceMissing)
                        >
                            @if($isCurrent)
                                Current plan
                            @elseif($stripePriceMissing)
                                Payment unavailable
                            @elseif($requiresPayment)
                                Subscribe & pay
                            @else
                                Choose {{ $plan->name }}
                            @endif
                        </button>
                    </form>
                </article>
            @endforeach
        </section>

        @if($subscription&&$subscription->plan->slug!=='free')
            <form class="cancel-subscription" method="POST" action="{{ route('billing.cancel') }}">
                @csrf @method('DELETE')
                <button type="submit" onclick="return confirm('Cancel at the end of this billing period?')">Cancel subscription</button>
            </form>
        @endif
    </div>

    <section class="card commercial-table billing-panel" x-show="tab==='invoices'">
        <div class="card-head">
            <div>
                <h2>Invoices</h2>
                <p>Your complete workspace billing history.</p>
            </div>
        </div>
        <div class="commercial-table-head invoice-columns">
            <span>Invoice</span><span>Date</span><span>Status</span><span>Amount</span><span></span>
        </div>
        @forelse($invoices as $invoice)
            <div class="commercial-table-row invoice-columns">
                <span>
                    <strong>{{ $invoice->number??$invoice->uuid }}</strong>
                    <small>{{ $invoice->line_items[0]['description']??'Subscription' }}</small>
                </span>
                <span>{{ $invoice->created_at->format('M d, Y') }}</span>
                <span><em class="status status--{{ $invoice->status==='paid'?'success':'warning' }}"><i></i>{{ ucfirst($invoice->status) }}</em></span>
                <strong>{{ $invoice->totalLabel() }}</strong>
                <span>
                    @if($invoice->invoice_pdf)
                        <a href="{{ $invoice->invoice_pdf }}"><i data-lucide="download"></i></a>
                    @else
                        <span>—</span>
                    @endif
                </span>
            </div>
        @empty
            <div class="healthy-empty">
                <i data-lucide="receipt"></i>
                <strong>No invoices yet</strong>
                <small>Your first invoice appears after subscribing.</small>
            </div>
        @endforelse
    </section>

    <section class="card commercial-table billing-panel" x-show="tab==='infrastructure'">
        <div class="card-head">
            <div>
                <h2>Managed infrastructure charges</h2>
                <p>Compute charges are metered separately from your Uplary subscription.</p>
            </div>
            <div class="billing-outstanding">
                <small>Outstanding</small>
                <strong>${{ number_format($infrastructureCharges->where('status','pending')->sum('total') / 100, 2) }}</strong>
            </div>
        </div>
        <div class="commercial-table-head invoice-columns">
            <span>Resource</span><span>Period</span><span>Status</span><span>Amount</span><span></span>
        </div>
        @forelse($infrastructureCharges as $charge)
            <div class="commercial-table-row invoice-columns">
                <span>
                    <strong>{{ $charge->server?->name ?? 'Removed server' }}</strong>
                    <small>{{ $charge->description }}</small>
                </span>
                <span>{{ $charge->period_starts_at->format('M d') }} – {{ $charge->period_ends_at->format('M d, Y') }}</span>
                <span><em class="status status--{{ $charge->status==='paid'?'success':'warning' }}"><i></i>{{ ucfirst($charge->status) }}</em></span>
                <strong>${{ number_format($charge->total / 100, 2) }}</strong>
                <span>{{ strtoupper($charge->currency) }}</span>
            </div>
        @empty
            <div class="healthy-empty">
                <i data-lucide="cloud"></i>
                <strong>No infrastructure charges</strong>
                <small>Managed server compute charges appear here after provisioning.</small>
            </div>
        @endforelse
    </section>

    <section class="payment-grid billing-panel" x-show="tab==='payment'">
        @forelse($paymentMethods as $method)
            <article class="card payment-card">
                <span class="card-brand">{{ strtoupper($method->brand??$method->type) }}</span>
                <strong>•••• •••• •••• {{ $method->last_four }}</strong>
                <small>Expires {{ str_pad($method->expiry_month,2,'0',STR_PAD_LEFT) }}/{{ $method->expiry_year }}</small>
                @if($method->is_default)<em>Default</em>@endif
            </article>
        @empty
            <div class="card healthy-empty">
                <i data-lucide="credit-card"></i>
                <strong>No payment method</strong>
                <small>Add one securely through the Stripe customer portal.</small>
            </div>
        @endforelse
    </section>

    <div class="billing-panel" x-show="tab==='usage'">
        @foreach(\App\Support\PlanCatalog::groupedQuotas() as $group => $quotas)
            <div class="usage-section-head"><h2>{{ $group }}</h2><p>Live usage against the quotas configured for your {{ $planAccess->plan()->name }} plan.</p></div>
            <section class="usage-grid">
                @foreach($quotas as $metric => $quota)
                    @php($limit = $planAccess->quota($metric))
                    <article class="card usage-card {{ $planAccess->atCapacity($metric, 0) ? 'usage-card--limit' : '' }}">
                        <span class="stat-icon stat-icon--purple"><i data-lucide="{{ match($metric) { 'servers' => 'server', 'managed_servers' => 'cloud', 'applications' => 'blocks', 'containers' => 'box', 'domains' => 'globe-2', 'volumes', 'volume_storage_gb' => 'database', 'team_members' => 'users', 'backups', 'backup_storage_gb' => 'archive', 'api_tokens' => 'key-round', default => 'activity' } }}"></i></span>
                        <div>
                            <small>{{ $quota['label'] }}</small>
                            @if($metric === 'monitoring_retention_days')
                                <strong>{{ $limit === null ? 'Unlimited' : $limit.' days' }}</strong><span>Metrics data retention</span>
                            @else
                                <strong>{{ $planAccess->usageLabel($metric) }}</strong><span>{{ $limit === null ? 'Unlimited quota' : ($planAccess->atCapacity($metric, 0) ? 'Limit reached' : $planAccess->remaining($metric).' remaining') }}</span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </section>
        @endforeach
    </div>
</div>
</x-dashboard-layout>
