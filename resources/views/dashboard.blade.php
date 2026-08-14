<x-dashboard-layout title="Dashboard">
    @php
        $primaryMetric = $primaryServer?->metrics?->first();
        $memoryPercent = $memoryLimitMb > 0 ? min(100, round(($memoryUsedMb / $memoryLimitMb) * 100)) : 0;
    @endphp

    <div class="page-heading dashboard-welcome">
        <div>
            <p class="dashboard-welcome__eyebrow">Workspace overview</p>
            <h1>Infrastructure at a glance</h1>
            <p>Monitor capacity, deploy applications, and keep your services healthy.</p>
        </div>
        <div class="heading-actions">
            <a href="{{ route('applications.index') }}" class="button button--secondary"><i data-lucide="blocks"></i> Browse apps</a>
        </div>
    </div>

    <section class="stats-grid dashboard-stats">
        @foreach($stats as $stat)
            <x-stat-card :label="$stat['label']" :value="$stat['value']" :detail="$stat['detail']" :icon="$stat['icon']" :tone="$stat['tone']" :href="$stat['href']" />
        @endforeach
    </section>

    <section class="dashboard-reference-grid">
        <div class="dashboard-reference-main">
            <article class="card marketplace-card dashboard-marketplace">
                <div class="card-head">
                    <div><h2>Application Marketplace</h2><p>Deploy open-source applications in one click.</p></div>
                    <a class="button button--secondary" href="{{ route('applications.installed') }}">View All Applications</a>
                </div>
                <div class="marketplace-grid">
                    @forelse($featuredApplications as $application)
                        <article class="marketplace-app">
                            <x-application-icon :application="$application" class="marketplace-app-icon" />
                            <strong>{{ $application->name }}</strong>
                            <small>{{ $application->category?->name ?? 'Application' }}</small>
                            <x-plan-locked-action feature="marketplace" quota="applications" label="Marketplace" class="button button--secondary">
                                <a href="{{ route('applications.install', $application) }}" class="button button--secondary">Install</a>
                            </x-plan-locked-action>
                        </article>
                    @empty
                        <div class="dashboard-empty"><i data-lucide="blocks"></i><p>No applications are available yet.</p></div>
                    @endforelse
                </div>
            </article>

            <article class="card deployments-card">
                <div class="card-head"><div><h2>Running Applications</h2></div><a href="{{ route('applications.installed') }}">View All</a></div>
                <div class="dashboard-deployments">
                    <div class="dashboard-deployments-head"><span>Application</span><span>Status</span><span>Domain</span><span>CPU</span><span>RAM</span><span>Uptime</span><span>Actions</span></div>
                    @forelse($deployments as $deployment)
                        <div class="dashboard-deployment-row">
                            <span class="deployment-app"><x-application-icon :application="$deployment->application" size="sm" class="deployment-app-icon" /><span><strong>{{ $deployment->name }}</strong><small>{{ $deployment->application?->name ?? $deployment->docker_image }}</small></span></span>
                            <span class="status status--{{ $deployment->status->tone() }}"><i></i>{{ $deployment->status->label() }}</span>
                            <span><strong>{{ $deployment->domain ?: 'Not assigned' }}</strong></span>
                            <span><strong>{{ $deployment->cpu_limit ?: '—' }}%</strong></span>
                            <span><strong>{{ $deployment->memory_limit_mb ?: '—' }} MB</strong></span>
                            <span><strong>{{ $deployment->deployed_at?->diffForHumans(null, true) ?: '—' }}</strong></span>
                            <a href="{{ route('deployments.show', $deployment) }}" class="icon-button" aria-label="Manage {{ $deployment->name }}"><i data-lucide="ellipsis"></i></a>
                        </div>
                    @empty
                        <div class="dashboard-empty"><i data-lucide="rocket"></i><p>No applications have been deployed.</p><a href="{{ route('applications.index') }}">Open marketplace</a></div>
                    @endforelse
                </div>
                <a class="dashboard-table-footer" href="{{ route('applications.installed') }}">View All Applications</a>
            </article>
        </div>

        <aside class="dashboard-side-stack">
            <article class="card server-snapshot">
                <div class="card-head"><div><h2>Server Overview</h2></div><a href="{{ route('servers.index') }}">View All Servers</a></div>
                @if($primaryServer)
                    <div class="server-snapshot-body">
                        <div class="snapshot-title">
                            <div><strong>{{ $primaryServer->name }}</strong><small>{{ $primaryServer->ip_address }} &nbsp;•&nbsp; {{ str($primaryServer->operating_system)->replace('-', ' ')->title() }} &nbsp;•&nbsp; Docker {{ $primaryServer->docker_version ?: 'Pending' }}</small></div>
                            <span class="status status--{{ $primaryServer->status->tone() }}"><i></i>{{ $primaryServer->status->label() }}</span>
                        </div>
                        @foreach([['CPU', $primaryMetric?->cpu_percent ?? 0], ['RAM', $primaryMetric?->memory_percent ?? 0], ['Disk', $primaryMetric?->disk_percent ?? 0]] as [$label, $value])
                            <div class="snapshot-metric"><span><small>{{ $label }} Usage</small><strong>{{ number_format((float) $value, 0) }}%</strong></span><div class="metric-track"><i style="width:{{ min(100, (float) $value) }}%" class="{{ $value > 75 ? 'warn' : '' }}"></i></div></div>
                        @endforeach
                        <div class="snapshot-uptime"><span>Uptime</span><strong>{{ $primaryServer->last_seen_at?->diffForHumans(null, true) ?? 'Not reported' }}</strong></div>
                    </div>
                @else
                    <div class="dashboard-empty"><i data-lucide="server-off"></i><p>No connected server is available.</p><a href="{{ route('servers.index') }}">View servers</a></div>
                @endif
            </article>

            <article class="card resource-usage-card">
                <div class="card-head"><div><h2>Resource Usage</h2></div></div>
                <div class="resource-usage-body">
                    <div class="usage-donut" style="--usage:{{ $memoryPercent }}"><span><strong>{{ $memoryPercent }}%</strong><small>RAM</small></span></div>
                    <dl>
                        <div><dt><i class="usage-key usage-key--purple"></i> Containers</dt><dd>{{ number_format($memoryUsedMb) }} MB</dd></div>
                        <div><dt><i class="usage-key usage-key--blue"></i> Applications</dt><dd>{{ $deployments->count() }}</dd></div>
                        <div><dt><i class="usage-key usage-key--green"></i> Available</dt><dd>{{ number_format(max(0, $memoryLimitMb - $memoryUsedMb)) }} MB</dd></div>
                    </dl>
                </div>
                <p class="resource-total">Total: {{ number_format($memoryLimitMb / 1024, 1) }} GB allocated</p>
            </article>

            <article class="card activity-card">
                <div class="card-head"><div><h2>Recent Activity</h2></div><a href="{{ route('activity.index') }}">View All</a></div>
                <div class="dashboard-activity-list">
                    @forelse($activities->take(5) as $activity)
                        <div class="dashboard-activity-item">
                            <span class="activity-icon activity-icon--{{ $activity->status === 'failed' ? 'failed' : 'success' }}"><i data-lucide="{{ $activity->status === 'failed' ? 'triangle-alert' : 'check' }}"></i></span>
                            <span><strong>{{ $activity->description }}</strong><small>{{ $activity->user?->name ?? 'System' }} &nbsp;•&nbsp; {{ $activity->created_at?->diffForHumans() }}</small></span>
                        </div>
                    @empty
                        <div class="dashboard-empty"><i data-lucide="history"></i><p>No activity has been recorded.</p></div>
                    @endforelse
                </div>
            </article>
        </aside>
    </section>

    <article class="card dashboard-system-card">
        <div class="card-head"><div><h2>System Information</h2></div></div>
        <div class="system-facts">
            <span><i data-lucide="circle-dot"></i><small>OS</small><strong>{{ $primaryServer ? str($primaryServer->operating_system)->replace('-', ' ')->title() : 'Not connected' }}</strong></span>
            <span><i data-lucide="container"></i><small>Docker Engine</small><strong>{{ $primaryServer?->docker_version ?: 'Pending' }}</strong></span>
            <span><i data-lucide="file-stack"></i><small>Docker Compose</small><strong>{{ $primaryServer?->docker_compose_version ?: 'Pending' }}</strong></span>
            <span><i data-lucide="shield"></i><small>Firewall</small><strong>Active</strong></span>
            <span><i data-lucide="route"></i><small>Reverse Proxy</small><strong>{{ $primaryServer?->proxy_status === 'running' ? ($primaryServer->proxy_version ?: 'Active') : 'Not installed' }}</strong></span>
            <span><i data-lucide="globe-2"></i><small>Timezone</small><strong>{{ config('app.timezone') }}</strong></span>
        </div>
    </article>
</x-dashboard-layout>
