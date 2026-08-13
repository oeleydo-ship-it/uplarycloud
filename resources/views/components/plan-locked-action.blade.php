@props([
    'feature' => null,
    'quota' => null,
    'label' => null,
])

@php
    $access = $planAccess ?? (app(\App\Support\TenantContext::class)->has() ? app(\App\Support\CurrentPlanAccess::class) : null);
    $featureBlocked = $feature && $access && ! $access->can($feature);
    $quotaBlocked = $quota && $access && $access->atCapacity($quota);
    $locked = $featureBlocked || $quotaBlocked;
    $copy = $label ?? ($featureBlocked ? \App\Support\PlanCatalog::label($feature) : 'Upgrade plan');
@endphp

@if($locked)
    <a href="{{ route('billing.index') }}" {{ $attributes->merge(['class' => 'button button--secondary plan-upgrade-button']) }} title="Upgrade your plan to continue">
        <i data-lucide="sparkles"></i>
        Upgrade{{ $copy ? ' · '.$copy : '' }}
    </a>
@else
    {{ $slot }}
@endif
