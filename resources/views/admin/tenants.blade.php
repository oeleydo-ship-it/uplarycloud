<x-admin-layout title="Tenants">
    <div class="admin-heading"><div><p>SUPERADMIN / TENANTS</p><h1>Tenant Management</h1><span>Review usage and control the subscription that drives every feature gate and quota.</span></div></div>
    <form class="admin-search" method="get"><i data-lucide="search"></i><input name="search" value="{{ request('search') }}" placeholder="Search tenants"><button>Search</button></form>
    <article class="card admin-table-card">
        <table>
            <thead><tr><th>Workspace</th><th>Usage</th><th>Current subscription</th><th>Plan control</th><th>Created</th></tr></thead>
            <tbody>
            @foreach($tenants as $tenant)
                @php($subscription = $tenant->latestSubscription)
                <tr>
                    <td><strong>{{ $tenant->name }}</strong><small>{{ $tenant->slug }}</small></td>
                    <td><strong>{{ $tenant->servers_count }} servers · {{ $tenant->deployments_count }} apps</strong><small>{{ $tenant->users_count }} members</small></td>
                    <td><strong>{{ $subscription?->plan?->name ?? 'Free fallback' }}</strong><small>{{ $subscription ? ucfirst(str_replace('_', ' ', $subscription->status)) : 'No subscription record' }}</small></td>
                    <td>
                        <form method="post" action="{{ route('admin.tenants.subscription.update', $tenant) }}" class="admin-subscription-control">
                            @csrf @method('PUT')
                            <select name="plan_id" aria-label="Plan for {{ $tenant->name }}" required>
                                @foreach($plans as $plan)<option value="{{ $plan->id }}" @selected($subscription?->plan_id === $plan->id)>{{ $plan->name }}{{ $plan->active ? '' : ' (hidden)' }}</option>@endforeach
                            </select>
                            <select name="status" aria-label="Subscription status for {{ $tenant->name }}">
                                @foreach(['active', 'trialing', 'past_due', 'canceled'] as $status)<option value="{{ $status }}" @selected(($subscription?->status ?? 'active') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach
                            </select>
                            <select name="billing_cycle" aria-label="Billing cycle for {{ $tenant->name }}"><option value="monthly" @selected(($subscription?->billing_cycle ?? 'monthly') === 'monthly')>Monthly</option><option value="yearly" @selected($subscription?->billing_cycle === 'yearly')>Yearly</option></select>
                            <button class="button button--secondary">Apply</button>
                        </form>
                    </td>
                    <td>{{ $tenant->created_at?->format('M j, Y') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="admin-pagination">{{ $tenants->links() }}</div>
    </article>
</x-admin-layout>
