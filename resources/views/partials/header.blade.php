<header class="topbar">
    <div class="topbar-left">
        <button class="icon-button topbar-menu" @click="sidebarOpen=true" aria-label="Open navigation"><i data-lucide="menu"></i></button>
        @if(request()->routeIs('dashboard'))
            <strong class="topbar-title">Dashboard</strong>
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
        <button class="icon-button notification-button"><i data-lucide="bell"></i><span></span></button>
        <div class="profile-menu">
            <button class="profile-trigger" @click="profileOpen=!profileOpen">
                <span class="avatar avatar--small">{{ str(auth()->user()->name)->substr(0, 2)->upper() }}</span>
                @if(request()->routeIs('dashboard', 'servers.*', 'containers.*', 'domains.*', 'volumes.*', 'logs.*', 'monitoring.*'))<span class="profile-name"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->tenants()->first()?->pivot?->role ? str(auth()->user()->tenants()->first()->pivot->role)->headline() : 'Administrator' }}</small></span>@endif
                <i data-lucide="chevron-down"></i>
            </button>
            <div class="profile-dropdown" x-cloak x-show="profileOpen" @click.outside="profileOpen=false" x-transition>
                <div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div>
                @if(auth()->user()->is_super_admin)<a class="profile-admin-link" href="{{ route('admin.dashboard') }}"><i data-lucide="shield-check"></i> Platform Console</a>@endif
                <form method="POST" action="{{ route('logout') }}">@csrf<button><i data-lucide="log-out"></i> Sign out</button></form>
            </div>
        </div>
    </div>
</header>
