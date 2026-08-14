@php
    $headerTenantId = app(\App\Support\TenantContext::class)->id();
    $headerNotificationQuery = auth()->user()->notifications()->where('data->tenant_id', $headerTenantId);
    $headerNotifications = (clone $headerNotificationQuery)->latest()->limit(8)->get();
    $headerUnreadCount = (clone $headerNotificationQuery)->whereNull('read_at')->count();
@endphp
<header class="topbar">
    <div class="topbar-left">
        <button
            class="icon-button topbar-menu"
            @click="window.innerWidth < 1024 ? sidebarOpen = true : sidebarCollapsed = !sidebarCollapsed"
            :aria-label="window.innerWidth < 1024 ? 'Open navigation' : (sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar')"
            :title="window.innerWidth < 1024 ? 'Open navigation' : (sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar')"
        >
            <i data-lucide="menu" class="topbar-menu__mobile"></i>
            <i data-lucide="panel-left-close" class="topbar-menu__desktop" x-show="!sidebarCollapsed"></i>
            <i data-lucide="panel-left-open" class="topbar-menu__desktop" x-cloak x-show="sidebarCollapsed"></i>
        </button>
        @if(request()->routeIs('dashboard'))
            <strong class="topbar-title">Dashboard</strong><span class="topbar-context">Control center</span>
        @else
            <div class="workspace-switcher"><span class="workspace-dot"></span><span>{{ app(\App\Support\TenantContext::class)->current()->name }}</span><i data-lucide="chevron-down"></i></div>
        @endif
    </div>
    <div class="topbar-actions">
        @if(request()->routeIs('dashboard', 'servers.*', 'containers.*', 'domains.*', 'volumes.*', 'logs.*', 'monitoring.*'))
            @php
                $headerServerQuery = app(\App\Support\TenantContext::class)->current()->servers();
                $headerServerId = request()->filled('server_id')
                    ? request('server_id')
                    : (request()->filled('server') ? request('server') : null);
                $headerServer = $headerServerId
                    ? $headerServerQuery->find($headerServerId)
                    : $headerServerQuery->orderByRaw("case when status = 'online' then 0 else 1 end")->first();
            @endphp
            <a href="{{ route('servers.index') }}" class="dashboard-server-switcher"><strong>{{ $headerServer?->name ?? app(\App\Support\TenantContext::class)->current()->name }}</strong><span><i></i>{{ $headerServer?->status?->label() ?? 'Workspace' }}</span><i data-lucide="chevron-down"></i></a>
        @else
            <span class="system-status"><span></span> Platform online</span>
            <button class="icon-button"><i data-lucide="search"></i></button>
        @endif
        <div class="notification-menu" x-data="{ open: false }">
            <button class="icon-button notification-button" @click="open = !open" :aria-expanded="open" aria-label="Notifications">
                <i data-lucide="bell"></i>
                @if($headerUnreadCount > 0)<span>{{ $headerUnreadCount > 99 ? '99+' : $headerUnreadCount }}</span>@endif
            </button>
            <div class="notification-dropdown" x-cloak x-show="open" @click.outside="open = false" x-transition>
                <div class="notification-dropdown__head">
                    <div><strong>Notifications</strong><small>{{ $headerUnreadCount }} unread</small></div>
                    @if($headerUnreadCount > 0)
                        <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button type="submit">Mark all read</button></form>
                    @endif
                </div>
                <div class="notification-dropdown__list">
                    @forelse($headerNotifications as $notification)
                        @php($severity = $notification->data['severity'] ?? 'info')
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                            @csrf
                            <button type="submit" class="notification-item {{ $notification->read_at ? '' : 'is-unread' }}">
                                <span class="notification-item__icon is-{{ $severity }}"><i data-lucide="{{ $severity === 'error' ? 'triangle-alert' : ($severity === 'success' ? 'circle-check' : 'bell') }}"></i></span>
                                <span class="notification-item__copy">
                                    <strong>{{ $notification->data['title'] ?? 'Platform update' }}</strong>
                                    <small>{{ $notification->data['message'] ?? '' }}</small>
                                    <time>{{ $notification->created_at->diffForHumans() }}</time>
                                </span>
                                @unless($notification->read_at)<i class="notification-item__dot"></i>@endunless
                            </button>
                        </form>
                    @empty
                        <div class="notification-empty"><i data-lucide="bell-off"></i><strong>No notifications yet</strong><small>Deployment and server updates will appear here.</small></div>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="profile-menu">
            <button class="profile-trigger" @click="profileOpen=!profileOpen">
                <span class="avatar avatar--small">{{ str(auth()->user()->name)->substr(0, 2)->upper() }}</span>
                @if(request()->routeIs('dashboard', 'servers.*', 'containers.*', 'domains.*', 'volumes.*', 'logs.*', 'monitoring.*'))<span class="profile-name"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->tenants()->first()?->pivot?->role ? str(auth()->user()->tenants()->first()->pivot->role)->headline() : 'Administrator' }}</small></span>@endif
                <i data-lucide="chevron-down"></i>
            </button>
            <div class="profile-dropdown" x-cloak x-show="profileOpen" @click.outside="profileOpen=false" x-transition>
                <div class="profile-dropdown__header">
                    <strong>{{ auth()->user()->name }}</strong>
                    <small>{{ auth()->user()->email }}</small>
                </div>
                <div class="profile-dropdown__list">
                    <a href="{{ route('settings') }}" class="profile-dropdown__item {{ request()->routeIs('settings*') ? 'is-active' : '' }}">
                        <i data-lucide="settings-2"></i><span>Settings</span>
                    </a>
                    @if(auth()->user()->is_super_admin)
                        <a class="profile-dropdown__item profile-admin-link" href="{{ route('admin.dashboard') }}">
                            <i data-lucide="shield-check"></i><span>Platform Console</span>
                        </a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="profile-dropdown__item profile-dropdown__item--danger">
                            <i data-lucide="log-out"></i><span>Sign out</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
