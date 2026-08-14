<x-dashboard-layout title="Payment required">
    <section class="managed-payment-gate">
        <span class="managed-payment-gate__icon"><i data-lucide="credit-card"></i></span>
        <p class="breadcrumb">Managed servers</p>
        <h1>Payment required</h1>
        <p>{{ $message }}</p>

        <div class="managed-payment-gate__details">
            <span><i data-lucide="shield-check"></i></span>
            <div>
                <strong>Billing protects managed infrastructure</strong>
                <small>A paid workspace subscription must remain active before infrastructure can be provisioned.</small>
            </div>
        </div>

        <div class="managed-payment-gate__actions">
            @if($canManageBilling)
                <a href="{{ route('billing.index') }}" class="button button--primary">
                    <i data-lucide="credit-card"></i>Choose a paid plan
                </a>
            @else
                <span class="managed-payment-gate__owner-note">Ask a workspace owner to activate billing.</span>
            @endif
            <a href="{{ route('servers.index') }}" class="button button--secondary">Back to servers</a>
        </div>
    </section>
</x-dashboard-layout>
