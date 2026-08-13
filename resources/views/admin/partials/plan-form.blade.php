@php
    $catalogDefaults = \App\Support\PlanCatalog::defaultsFor($plan?->slug ?? 'pro');
    $planGates = $plan?->gates ?? $catalogDefaults['gates'];
    $groupedGates = \App\Support\PlanCatalog::groupedGates();
    $groupedQuotas = \App\Support\PlanCatalog::groupedQuotas();
@endphp
<form method="post" action="{{ $plan ? route('admin.plans.update', $plan) : route('admin.plans.store') }}">
    @csrf
    @if($plan)@method('PUT')@endif
    <h2>{{ $plan ? 'Edit '.$plan->name : 'Create Subscription Plan' }}</h2>
    <p class="admin-plan-lead">Pricing, gated features, and numeric quotas. Leave a quota empty for unlimited.</p>

    <div class="admin-form-grid admin-form-grid--plan">
        <label><span>Name</span><input name="name" value="{{ $plan?->name }}" required></label>
        <label><span>Slug</span><input name="slug" value="{{ $plan?->slug }}" required></label>
        <label class="wide"><span>Description</span><input name="description" value="{{ $plan?->description }}"></label>
        <label><span>Monthly price</span><input type="number" step=".01" name="monthly_price" value="{{ $plan ? number_format($plan->monthly_price/100, 2, '.', '') : '0.00' }}"></label>
        <label><span>Yearly price</span><input type="number" step=".01" name="yearly_price" value="{{ $plan ? number_format($plan->yearly_price/100, 2, '.', '') : '0.00' }}"></label>
        <label><span>Currency</span><input name="currency" maxlength="3" value="{{ $plan?->currency ?? 'USD' }}"></label>
        <label><span>Stripe monthly price ID</span><input name="stripe_monthly_price_id" value="{{ $plan?->stripe_monthly_price_id }}"></label>
        <label><span>Stripe yearly price ID</span><input name="stripe_yearly_price_id" value="{{ $plan?->stripe_yearly_price_id }}"></label>
    </div>

    <div class="admin-plan-section">
        <h3>Gated features</h3>
        <p>Turn a capability on or off for this plan. Disabled features are hidden in the tenant console.</p>
        @foreach($groupedGates as $group => $gates)
            <strong class="admin-plan-group">{{ $group }}</strong>
            <div class="admin-switches admin-switches--plan">
                @foreach($gates as $key => $gate)
                    <label>
                        <input type="checkbox" name="gate_{{ $key }}" value="1" @checked($planGates[$key] ?? false)>
                        <span>
                            <strong>{{ $gate['label'] }}</strong>
                            <small>{{ $gate['description'] }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="admin-plan-section">
        <h3>Quotas</h3>
        <p>Numeric limits enforced on create actions. Empty means unlimited.</p>
        @foreach($groupedQuotas as $group => $quotas)
            <strong class="admin-plan-group">{{ $group }}</strong>
            <div class="admin-form-grid admin-form-grid--plan">
                @foreach($quotas as $key => $quota)
                    <label>
                        <span>{{ $quota['label'] }}</span>
                        <input type="number" min="0" name="{{ $key }}" value="{{ $plan ? $plan->limit($key) : ($catalogDefaults['limits'][$key] ?? '') }}" placeholder="Unlimited">
                    </label>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="admin-form-grid admin-form-grid--plan">
        <label class="wide"><span>Marketing features (one per line)</span><textarea name="features">{{ implode("\n", $plan?->features ?? []) }}</textarea></label>
    </div>
    <label class="admin-check"><input type="checkbox" name="active" value="1" @checked($plan?->active ?? true)>Active</label>
    <label class="admin-check"><input type="checkbox" name="featured" value="1" @checked($plan?->featured)>Featured</label>
    <div>
        <button type="button" class="button button--secondary" onclick="this.closest('dialog').close()">Cancel</button>
        <button class="button button--primary">Save Plan</button>
    </div>
</form>
