<x-dashboard-layout :title="'Upgrade required'">
    <div class="plan-locked">
        <span class="plan-locked-icon"><i data-lucide="sparkles"></i></span>
        <h1>{{ \App\Support\PlanCatalog::label($feature) }} is not on your plan</h1>
        <p>Your <strong>{{ $plan->name }}</strong> plan does not include this feature. Upgrade to unlock it for this workspace.</p>
        <div class="plan-locked-actions">
            <a href="{{ route('billing.index') }}" class="button button--primary"><i data-lucide="credit-card"></i> View plans</a>
            <a href="{{ route('dashboard') }}" class="button button--secondary">Back to dashboard</a>
        </div>
    </div>
</x-dashboard-layout>
