@props([
    'feature' => null,
    'quota' => null,
])

@php
    $access = $planAccess ?? (app(\App\Support\TenantContext::class)->has() ? app(\App\Support\CurrentPlanAccess::class) : null);
    $featureBlocked = $feature && $access && ! $access->can($feature);
    $quotaBlocked = $quota && $access && $access->atCapacity($quota);
    $show = $featureBlocked || $quotaBlocked;
    $message = $featureBlocked
        ? \App\Support\PlanCatalog::label($feature).' is not included in your '.$access->plan()->name.' plan.'
        : "You've reached the ".\App\Support\PlanCatalog::label($quota).' limit on your '.$access?->plan()->name.' plan.';
@endphp

@if($show)
    <section {{ $attributes->merge(['class' => 'plan-upgrade-banner']) }}>
        <i data-lucide="sparkles"></i>
        <span>
            <strong>{{ $featureBlocked ? 'Feature unavailable' : 'Plan limit reached' }}</strong>
            <small>{{ $message }} Upgrade to continue.</small>
        </span>
        <a href="{{ route('billing.index') }}" class="button button--primary button--small">View plans</a>
    </section>
@endif
