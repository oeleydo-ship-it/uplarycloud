<x-admin-layout title="Subscription Plans">
    <div class="admin-heading">
        <div>
            <p>SUPERADMIN / BILLING</p>
            <h1>Subscription Plans</h1>
            <span>Control pricing, gated features, quotas, and gateway price mappings.</span>
        </div>
        <button class="button button--primary" onclick="document.getElementById('new-plan').showModal()"><i data-lucide="plus"></i>New Plan</button>
    </div>
    <section class="admin-plan-grid">
        @foreach($plans as $plan)
            <article class="card">
                <div>
                    <span class="admin-pill {{ $plan->featured ? 'admin-pill--purple' : '' }}">{{ $plan->active ? 'Active' : 'Hidden' }}</span>
                    <strong>{{ $plan->subscriptions_count }} subscriptions</strong>
                </div>
                <h2>{{ $plan->name }}</h2>
                <p>{{ $plan->description }}</p>
                <div class="admin-price">
                    <strong>{{ strtoupper($plan->currency) }} {{ number_format($plan->monthly_price/100, 2) }}</strong>
                    <span>/ month</span>
                </div>
                <ul>
                    @foreach($plan->features ?? [] as $feature)
                        <li><i data-lucide="check"></i>{{ $feature }}</li>
                    @endforeach
                    <li><i data-lucide="server"></i>{{ $plan->limit('servers') ?? 'Unlimited' }} servers</li>
                    <li><i data-lucide="blocks"></i>{{ $plan->limit('applications') ?? 'Unlimited' }} applications</li>
                    <li><i data-lucide="users"></i>{{ $plan->limit('team_members') ?? 'Unlimited' }} members</li>
                    <li><i data-lucide="globe-2"></i>{{ $plan->limit('domains') ?? 'Unlimited' }} domains</li>
                </ul>
                <div class="admin-plan-gates">
                    @foreach(\App\Support\PlanCatalog::gates() as $key => $gate)
                        <em class="{{ $plan->allowsFeature($key) ? 'is-on' : 'is-off' }}">{{ $gate['label'] }}</em>
                    @endforeach
                </div>
                <button class="button button--secondary button--full" onclick="document.getElementById('plan-{{ $plan->id }}').showModal()">Edit Plan</button>
            </article>
            <dialog id="plan-{{ $plan->id }}" class="admin-dialog admin-dialog--plan">@include('admin.partials.plan-form', ['plan' => $plan])</dialog>
        @endforeach
    </section>
    <dialog id="new-plan" class="admin-dialog admin-dialog--plan">@include('admin.partials.plan-form', ['plan' => null])</dialog>
</x-admin-layout>
