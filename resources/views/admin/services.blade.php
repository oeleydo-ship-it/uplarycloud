<x-admin-layout title="Platform Services">
    <div class="admin-heading">
        <div><p>SUPERADMIN / PLATFORM SERVICES</p><h1>Platform Services</h1><span>Monitor and control background jobs and realtime updates.</span></div>
        <a class="button button--secondary" href="{{ route('admin.services') }}"><i data-lucide="refresh-cw"></i> Refresh status</a>
    </div>
    <div class="admin-service-warning"><i data-lucide="triangle-alert"></i><div><strong>Service actions affect every workspace</strong><span>Stopping Horizon pauses queued deployments and provisioning. Stopping Reverb interrupts live updates until clients reconnect.</span></div></div>
    <section class="admin-service-grid">
        @foreach($services as $key => $service)
            <article class="card admin-service-card">
                <div class="admin-service-card-head"><span class="admin-service-icon"><i data-lucide="{{ $service['icon'] }}"></i></span><div><h2>{{ $service['name'] }}</h2><p>{{ $service['description'] }}</p></div><span class="admin-service-status admin-service-status--{{ $service['status'] }}"><i></i>{{ $service['status_label'] }}</span></div>
                <div class="admin-service-detail"><span>Supervisor program</span><code>{{ $service['program'] }}</code></div>
                <div class="admin-service-detail"><span>Current status</span><small>{{ $service['detail'] }}</small></div>
                <div class="admin-service-actions">
                    @if($service['status'] === 'running')
                        <form method="post" action="{{ route('admin.services.control', [$key, 'stop']) }}" onsubmit="return confirm('Stop {{ $service['name'] }}? This affects all workspaces.')">@csrf<button class="button admin-service-stop"><i data-lucide="square"></i> Stop</button></form>
                    @else
                        <form method="post" action="{{ route('admin.services.control', [$key, 'start']) }}">@csrf<button class="button button--primary" @disabled($service['status'] === 'unavailable')><i data-lucide="play"></i> Start</button></form>
                    @endif
                    <form method="post" action="{{ route('admin.services.control', [$key, 'restart']) }}" onsubmit="return confirm('Restart {{ $service['name'] }} now?')">@csrf<button class="button button--secondary" @disabled($service['status'] === 'unavailable')><i data-lucide="rotate-cw"></i> Restart</button></form>
                    @if(isset($service['dashboard_route']) && Route::has($service['dashboard_route']))<a class="admin-service-link" href="{{ route($service['dashboard_route']) }}"><i data-lucide="external-link"></i> Open dashboard</a>@endif
                </div>
            </article>
        @endforeach
    </section>
    <article class="card admin-service-setup"><span><i data-lucide="square-terminal"></i></span><div><h2>Process manager connection</h2><p>Controls use Supervisor with fixed, allowlisted program names. The PHP user needs permission to run <code>{{ config('platform_services.use_sudo') ? config('platform_services.sudo').' -n ' : '' }}{{ config('platform_services.supervisorctl') }}</code> for <code>{{ collect(config('platform_services.services'))->pluck('program')->join(', ') }}</code>.</p></div></article>
</x-admin-layout>
