<x-admin-layout title="Users">
    <div class="admin-heading">
        <div><p>SUPERADMIN / USERS</p><h1>Users</h1><span>Manage accounts, review current plans, and enter customer consoles for support.</span></div>
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
                <dialog id="user-{{ $user->id }}" class="admin-dialog">
                    <form method="post" action="{{ route('admin.users.update', $user) }}">@csrf @method('PUT')
                        <h2>Edit User</h2>
                        <label><span>Name</span><input name="name" value="{{ $user->name }}" required></label>
                        <label><span>Email</span><input type="email" name="email" value="{{ $user->email }}" required></label>
                        <label class="admin-check"><input type="checkbox" name="is_super_admin" value="1" @checked($user->is_super_admin)>Superadmin access</label>
                        <label class="admin-check"><input type="checkbox" name="email_verified" value="1" @checked($user->email_verified_at)>Email verified</label>
                        <div><button type="button" class="button button--secondary" onclick="this.closest('dialog').close()">Cancel</button><button class="button button--primary">Save User</button></div>
                    </form>
                </dialog>
            @endforeach
            </tbody>
        </table>
        <div class="admin-pagination">{{ $users->links() }}</div>
    </article>
    <dialog id="new-user" class="admin-dialog">
        <form method="post" action="{{ route('admin.users.store') }}">@csrf
            <h2>Create User</h2>
            <label><span>Name</span><input name="name" required></label>
            <label><span>Email</span><input type="email" name="email" required></label>
            <label><span>Temporary password</span><input type="password" name="password" required minlength="8"></label>
            <label class="admin-check"><input type="checkbox" name="is_super_admin" value="1">Grant superadmin access</label>
            <div><button type="button" class="button button--secondary" onclick="this.closest('dialog').close()">Cancel</button><button class="button button--primary">Create User</button></div>
        </form>
    </dialog>
</x-admin-layout>
