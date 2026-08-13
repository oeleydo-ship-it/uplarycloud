<x-dashboard-layout title="Feature unavailable">
    <div class="plan-locked">
        <span class="plan-locked-icon"><i data-lucide="settings-2"></i></span>
        <h1>This feature is temporarily unavailable</h1>
        <p><strong>{{ str($feature)->replace('_', ' ')->title() }}</strong> has been disabled platform-wide by your administrator. Your workspace data is unchanged.</p>
        <div class="plan-locked-actions">
            <a href="{{ route('dashboard') }}" class="button button--primary">Back to dashboard</a>
            @if ($feature !== 'support')
                <a href="{{ route('support.index') }}" class="button button--secondary">Contact support</a>
            @endif
        </div>
    </div>
</x-dashboard-layout>
