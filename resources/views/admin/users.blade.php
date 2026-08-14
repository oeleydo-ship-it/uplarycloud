<x-admin-layout title="Users">
    <div class="admin-heading">
        <div><p>SUPERADMIN / USERS</p><h1>Users</h1><span>Manage accounts, upgrade workspace plans, and enter customer consoles for support.</span></div>
        <button class="button button--primary" onclick="document.getElementById('new-user').showModal()"><i data-lucide="user-plus"></i>Add User</button>
    </div>
    <form class="admin-search" method="get"><i data-lucide="search"></i><input name="search" value="{{ request('search') }}" placeholder="Search users by name or email"><button>Search</button></form>
    <article class="card admin-table-card">
        <table>
            <thead><tr><th>User</th><th>Access</th><th>Workspaces & current plans</th><th>Email</th><th>Last active</th><th>Actions</th></tr></thead>
            <tbody>
            @foreach($users as $user)
                <tr>
                    <td><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></td>
                    <td><span class="admin-pill {{ $user->is_super_admin ? 'admin-pill--purple' : '' }}">{{ $user->is_super_admin ? 'Superadmin' : 'Customer' }}</span></td>
                    <td>
                        @forelse($user->tenants as $workspace)
                            @php($workspaceSubscription = $workspace->latestSubscription)
                            <span class="admin-user-plan"><strong>{{ $workspace->name }}</strong><small>{{ $workspaceSubscription?->active() ? ($workspaceSubscription->plan?->name ?? 'Free') : 'Free' }} plan</small></span>
                        @empty
                            <small>No workspace access</small>
                        @endforelse
                    </td>
                    <td>{{ $user->email_verified_at ? 'Verified' : 'Pending' }}</td>
                    <td>{{ $user->last_active_at?->diffForHumans() ?? 'Never' }}</td>
                    <td>
                        <div class="admin-user-actions">
                            @if(! $user->is_super_admin && $user->tenants->where('pivot.is_active', true)->isNotEmpty())
                                <form method="post" action="{{ route('admin.users.impersonate', $user) }}" class="admin-impersonate-form" onsubmit="return confirm('Enter this customer console for support? All access will be audited.')">
                                    @csrf
                                    <select name="tenant_id" aria-label="Workspace to access for {{ $user->name }}">
                                        @foreach($user->tenants->where('pivot.is_active', true) as $workspace)
                                            @php($workspaceSubscription = $workspace->latestSubscription)
                                            <option value="{{ $workspace->id }}">{{ $workspace->name }} · {{ $workspaceSubscription?->active() ? ($workspaceSubscription->plan?->name ?? 'Free') : 'Free' }}</option>
                                        @endforeach
                                    </select>
                                    <button class="admin-support-button" title="Access user console"><i data-lucide="life-buoy"></i>Support access</button>
                                </form>
                            @endif
                            <button class="admin-edit" onclick="document.getElementById('user-{{ $user->id }}').showModal()">Edit</button>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="admin-pagination">{{ $users->links() }}</div>
    </article>

    @foreach($users as $user)
        <dialog id="user-{{ $user->id }}" class="admin-dialog admin-dialog--user">
            <form method="post" action="{{ route('admin.users.update', $user) }}">
                @csrf @method('PUT')
                <header class="admin-user-modal-head">
                    <span><i data-lucide="user-cog"></i></span>
                    <div><h2>Edit User</h2><p>Manage identity, platform privileges, and workspace subscriptions.</p></div>
                    <button type="button" aria-label="Close" onclick="this.closest('dialog').close()"><i data-lucide="x"></i></button>
                </header>

                <section class="admin-user-modal-section">
                    <div class="admin-user-section-title"><div><h3>Account details</h3><p>Changes apply to this user across the platform.</p></div></div>
                    <div class="admin-user-form-grid">
                        <label><span>Name</span><input name="name" value="{{ $user->name }}" required></label>
                        <label><span>Email address</span><input type="email" name="email" value="{{ $user->email }}" required></label>
                    </div>
                    <div class="admin-user-permissions">
                        <label class="admin-permission-card">
                            <input type="checkbox" name="is_super_admin" value="1" @checked($user->is_super_admin)>
                            <span><strong>Superadmin access</strong><small>Full platform access, including all plan features and quotas.</small></span>
                        </label>
                        <label class="admin-permission-card">
                            <input type="checkbox" name="email_verified" value="1" @checked($user->email_verified_at)>
                            <span><strong>Email verified</strong><small>Allow the user to access protected account features.</small></span>
                        </label>
                    </div>
                </section>

                @if($user->tenants->isNotEmpty() && $plans->isNotEmpty())
                    <section class="admin-user-modal-section admin-user-plan-section">
                        <div class="admin-user-section-title">
                            <div><h3>Workspace plans</h3><p>Upgrade, downgrade, or change billing status immediately.</p></div>
                            <a href="{{ route('admin.plans') }}">Manage plans <i data-lucide="arrow-up-right"></i></a>
                        </div>
                        <div class="admin-user-workspaces">
                            @foreach($user->tenants as $workspace)
                                @php($subscription = $workspace->latestSubscription)
                                <article class="admin-user-workspace">
                                    <div class="admin-user-workspace-name"><span><i data-lucide="building-2"></i></span><div><strong>{{ $workspace->name }}</strong><small>Current: {{ $subscription?->plan?->name ?? 'Free fallback' }}</small></div></div>
                                    <div class="admin-user-plan-controls">
                                        <label><span>Plan</span><select name="subscriptions[{{ $workspace->id }}][plan_id]" required>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected($subscription?->plan_id === $plan->id)>{{ $plan->name }}{{ $plan->active ? '' : ' (hidden)' }}</option>@endforeach</select></label>
                                        <label><span>Status</span><select name="subscriptions[{{ $workspace->id }}][status]">@foreach(['active', 'trialing', 'past_due', 'canceled'] as $status)<option value="{{ $status }}" @selected(($subscription?->status ?? 'active') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select></label>
                                        <label><span>Billing</span><select name="subscriptions[{{ $workspace->id }}][billing_cycle]"><option value="monthly" @selected(($subscription?->billing_cycle ?? 'monthly') === 'monthly')>Monthly</option><option value="yearly" @selected($subscription?->billing_cycle === 'yearly')>Yearly</option></select></label>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @elseif($user->tenants->isNotEmpty())
                    <section class="admin-user-modal-section admin-user-plan-section">
                        <div class="admin-user-section-title"><div><h3>Workspace plans</h3><p>Create a subscription plan before assigning an upgrade.</p></div><a href="{{ route('admin.plans') }}">Create plan <i data-lucide="arrow-up-right"></i></a></div>
                    </section>
                @endif

                <footer class="admin-user-modal-actions">
                    <span><i data-lucide="shield-check"></i> Superadmin changes are applied immediately.</span>
                    <div><button type="button" class="button button--secondary" onclick="this.closest('dialog').close()">Cancel</button><button class="button button--primary">Save User & Plans</button></div>
                </footer>
            </form>
        </dialog>
    @endforeach

    <dialog id="new-user" class="admin-dialog admin-dialog--user admin-dialog--new-user">
        <form method="post" action="{{ route('admin.users.store') }}">@csrf
            <header class="admin-user-modal-head"><span><i data-lucide="user-plus"></i></span><div><h2>Create User</h2><p>Add a new account and choose its platform access.</p></div><button type="button" aria-label="Close" onclick="this.closest('dialog').close()"><i data-lucide="x"></i></button></header>
            <section class="admin-user-modal-section">
                <div class="admin-user-form-grid"><label><span>Name</span><input name="name" required></label><label><span>Email address</span><input type="email" name="email" required></label><label class="is-wide"><span>Temporary password</span><input type="password" name="password" required minlength="8"></label></div>
                <label class="admin-permission-card"><input type="checkbox" name="is_super_admin" value="1"><span><strong>Grant superadmin access</strong><small>Includes unrestricted plan features, quotas, and platform management.</small></span></label>
            </section>
            <footer class="admin-user-modal-actions"><span></span><div><button type="button" class="button button--secondary" onclick="this.closest('dialog').close()">Cancel</button><button class="button button--primary">Create User</button></div></footer>
        </form>
    </dialog>
</x-admin-layout>
