@php
    $navigation = [
        ['Dashboard', 'layout-dashboard', 'dashboard'],
        ['Servers', 'server', 'servers.index'], ['Applications', 'blocks', 'applications.index'], ['Containers', 'box', 'containers.index'],
        ['Domains', 'globe-2', 'domains.index'], ['Volumes', 'database', 'volumes.index'], ['Backups', 'archive', 'backups.index'],
        ['Monitoring', 'activity', 'monitoring.index'], ['Logs', 'scroll-text', 'logs.index'],
        ['Users', 'users', 'team.index'], ['Activity', 'history', 'activity.index'], ['API Tokens', 'key-round', 'api-tokens.index'],
        ['Billing', 'credit-card', 'billing.index'], ['Support', 'life-buoy', 'support.index'],
    ];
    $sidebarTenant = app(\App\Support\TenantContext::class)->current();
    $sidebarSubscription = $sidebarTenant->currentSubscription();
    $sidebarPlan = $planAccess->plan() ?? $sidebarSubscription?->plan;
    $serverLimit = $planAccess->quota('servers');
    $serverUsage = $planAccess->usage('servers');
    $appUsage = $planAccess->usage('applications');
    $appLimit = $planAccess->quota('applications');
    $backupCount = $planAccess->usage('backups');
    $backupLimit = $planAccess->quota('backups');
    $sidebarRole = auth()->user()->tenants()->whereKey($sidebarTenant->id)->first()?->pivot->role;
    $canManageCloud = in_array($sidebarRole, ['owner', 'admin'], true);
    $managedServersEnabled = app(\App\Support\PlatformSettings::class)->managedServersEnabled() && $planAccess->can('managed_servers');
    $navGates = [
        'backups.index' => 'backups',
        'monitoring.index' => 'monitoring',
        'api-tokens.index' => 'api_tokens',
    ];
@endphp
<div class="mobile-scrim" x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen=false"></div>
<aside class="sidebar" :class="sidebarOpen && 'sidebar--open'">
    <div class="sidebar-head">
        <a href="{{ route('dashboard') }}" class="brand">
            <span class="brand-mark">@if($brand['logo'])<img src="{{ Storage::url($brand['logo']) }}" alt="">@else<i data-lucide="layers-3"></i>@endif</span>
            <span>{{ $brand['name'] }}</span>
        </a>
        <button class="icon-button mobile-only" @click="sidebarOpen=false"><i data-lucide="x"></i></button>
    </div>
    <div class="sidebar-scroll">
        <nav class="nav-list">
            @foreach($navigation as [$label, $icon, $route])
                @if($route)
                    @if(isset($navGates[$route]) && ! $planAccess->can($navGates[$route]))
                        {{-- Gated features stay off the sidebar; create actions still show an upgrade prompt. --}}
                    @elseif($route === 'servers.index' && $canManageCloud && ($planAccess->can('cloud_api') || $managedServersEnabled))
                        <div class="server-nav-group" x-data="{ cloudNavOpen: {{ request()->routeIs('managed.*', 'cloud-api.*', 'servers.create*') ? 'true' : 'false' }} }">
                            <div class="server-nav-parent {{ request()->routeIs('servers.*', 'managed.*', 'cloud-api.*') ? 'is-active' : '' }}">
                                <a href="{{ route('servers.index') }}"><i data-lucide="server"></i><span>Servers</span></a>
                                <button type="button" @click="cloudNavOpen=!cloudNavOpen" :aria-expanded="cloudNavOpen" aria-label="Toggle server menu">
                                    <i data-lucide="chevron-down" :class="cloudNavOpen && 'is-open'"></i>
                                </button>
                            </div>
                            <div class="server-nav-dropdown" x-cloak x-show="cloudNavOpen" x-transition>
                                @if($planAccess->can('cloud_api'))
                                <a href="{{ route('cloud-api.index') }}" class="nav-item nav-item--nested {{ request()->routeIs('cloud-api.*') ? 'is-active' : '' }}">
                                    <i data-lucide="key-round"></i><span>My Cloud API</span>
                                </a>
                                @endif
                                @if($managedServersEnabled)
                                    <a href="{{ route('managed.index') }}" class="nav-item nav-item--nested {{ request()->routeIs('managed.*') ? 'is-active' : '' }}">
                                        <i data-lucide="cloud-cog"></i><span>Managed Cloud</span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @else
                        <a href="{{ route($route) }}" class="nav-item {{ request()->routeIs(str_replace('.index', '.*', $route)) ? 'is-active' : '' }}">
                            <i data-lucide="{{ $icon }}"></i><span>{{ $label }}</span>
                        </a>
                    @endif
                @else
                    <span class="nav-item is-upcoming" aria-disabled="true"><i data-lucide="{{ $icon }}"></i><span>{{ $label }}</span></span>
                @endif
            @endforeach
        </nav>
    </div>
    <div class="sidebar-footer">
        <div class="plan-menu" x-data="{ planOpen: false }">
            <button type="button" class="plan-trigger" @click="planOpen=!planOpen" :aria-expanded="planOpen">
                <span class="plan-trigger-icon"><i data-lucide="sparkles"></i></span>
                <span class="plan-trigger-copy">
                    <small>Current Plan</small>
                    <strong>{{ ucfirst($sidebarPlan?->name ?? 'Free') }}</strong>
                </span>
                <i data-lucide="chevron-up" class="plan-trigger-chevron" :class="planOpen && 'is-open'"></i>
            </button>
            <div class="plan-dropdown" x-cloak x-show="planOpen" @click.outside="planOpen=false" x-transition>
                <div class="plan-row"><span>Servers</span><strong>{{ $serverUsage }} / {{ $serverLimit ?? 'Unlimited' }}</strong></div>
                <div class="plan-row"><span>Apps</span><strong>{{ $appUsage }} / {{ $appLimit ?? 'Unlimited' }}</strong></div>
                <div class="plan-row"><span>Backups</span><strong>{{ $backupCount }} / {{ $backupLimit ?? 'Unlimited' }}</strong></div>
                <a href="{{ route('billing.index') }}" class="button button--primary button--full button--small">Upgrade Plan</a>
            </div>
        </div>
    </div>
</aside>
