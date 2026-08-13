@php
    $chartWidth = 760;
    $chartHeight = 150;
    $chartPadding = 20;
    $chartPlotWidth = $chartWidth - ($chartPadding * 2);
    $pointCount = max($chart->count(), 1);
    $step = $pointCount > 1 ? $chartPlotWidth / ($pointCount - 1) : 0;
    $cpuPoints = $chart->values()->map(fn ($point, $index) => ($chartPadding + ($index * $step)).','.(130 - min(100, $point['cpu'])))->join(' ');
    $memoryPoints = $chart->values()->map(fn ($point, $index) => ($chartPadding + ($index * $step)).','.(130 - min(100, $point['memory'])))->join(' ');
    $axisLabels = $chart->count() > 1
        ? collect([0, (int) floor(($pointCount - 1) / 2), $pointCount - 1])->map(fn ($index) => $chart->values()[$index]['label'] ?? '')
        : collect([$chart->first()['label'] ?? 'Now']);
    $rangeLabels = ['1h' => 'Last hour', '6h' => 'Last 6 hours', '24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];
@endphp

<x-dashboard-layout title="Monitoring">
<div class="monitoring-page">
    <div class="page-heading monitoring-reference-heading">
        <div>
            <p class="breadcrumb">
                <a href="{{ route('dashboard') }}">Operations</a>
                <i data-lucide="chevron-right"></i>
                Monitoring
            </p>
            <h1>Infrastructure monitoring</h1>
            <p>Live server and application health for <strong>{{ $contextLabel }}</strong>.</p>
        </div>
        <div class="heading-actions">
            <form method="POST" action="{{ route('monitoring.collect') }}">
                @csrf
                <button type="submit" class="button button--secondary">
                    <i data-lucide="refresh-cw"></i>
                    Refresh
                </button>
            </form>
            <x-plan-locked-action feature="alerts" label="alerts">
                <a href="{{ route('alerts.index') }}" class="button button--primary">
                    <i data-lucide="bell-ring"></i>
                    Alert rules
                </a>
            </x-plan-locked-action>
        </div>
    </div>

    <form class="monitoring-reference-filters" method="get">
        <label class="filter-select">
            <span class="visually-hidden">Server</span>
            <select name="server" onchange="this.form.submit()">
                <option value="">All servers</option>
                @foreach($serverOptions as $server)
                    <option value="{{ $server->id }}" @selected($serverId === $server->id)>{{ $server->name }}</option>
                @endforeach
            </select>
            <i data-lucide="chevron-down"></i>
        </label>
        <label class="filter-select">
            <span class="visually-hidden">Site</span>
            <select name="site" onchange="this.form.submit()">
                <option value="">All sites</option>
                @foreach($sites as $site)
                    <option value="{{ $site['id'] }}" @selected($siteId === $site['id'])>{{ $site['label'] }}</option>
                @endforeach
            </select>
            <i data-lucide="chevron-down"></i>
        </label>
        <label class="filter-select">
            <span class="visually-hidden">Time range</span>
            <select name="range" onchange="this.form.submit()">
                @foreach($rangeLabels as $key => $label)
                    <option value="{{ $key }}" @selected($range === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <i data-lucide="chevron-down"></i>
        </label>
    </form>

    <section class="stats-grid monitoring-reference-stats">
        <x-stat-card label="Average CPU" :value="$summary['cpu'].'%'" :detail="$summary['scope'] === 'site' ? 'Across selected site containers' : 'Across filtered servers'" icon="cpu" tone="purple" />
        <x-stat-card label="Memory usage" :value="$summary['memory'].'%'" :detail="$summary['scope'] === 'site' ? 'Container memory utilization' : 'Current average'" icon="memory-stick" tone="blue" />
        <x-stat-card label="Disk usage" :value="$summary['disk'].'%'" detail="Allocated storage" icon="hard-drive" tone="orange" />
        <x-stat-card label="Online servers" :value="$summary['online'].' / '.$summary['servers']" detail="Reporting normally" icon="server" tone="green" />
    </section>

    <div class="monitoring-reference-grid">
        <section class="card monitoring-chart-card">
            <div class="monitoring-chart-toolbar">
                <div>
                    <h2>Resource utilization</h2>
                    <p>{{ $summary['scope'] === 'site' ? 'CPU and memory for the selected application deployment.' : 'Average CPU and memory utilization.' }}</p>
                </div>
                <span class="monitoring-range-pill">{{ $rangeLabels[$range] ?? 'Last 24 hours' }}</span>
            </div>
            <div class="chart-legend">
                <span><i class="cpu"></i> CPU</span>
                <span><i class="memory"></i> Memory</span>
            </div>
            <svg class="monitoring-line-chart" viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" preserveAspectRatio="none" role="img" aria-label="Resource utilization chart">
                <g class="chart-grid">
                    <line x1="{{ $chartPadding }}" y1="30" x2="{{ $chartWidth - $chartPadding }}" y2="30" />
                    <line x1="{{ $chartPadding }}" y1="80" x2="{{ $chartWidth - $chartPadding }}" y2="80" />
                    <line x1="{{ $chartPadding }}" y1="130" x2="{{ $chartWidth - $chartPadding }}" y2="130" />
                </g>
                <polyline class="cpu-line" points="{{ $cpuPoints }}" />
                <polyline class="memory-line" points="{{ $memoryPoints }}" />
            </svg>
            <div class="chart-axis">
                @foreach($axisLabels as $label)
                    <span>{{ $label }}</span>
                @endforeach
            </div>
        </section>

        <aside class="card monitoring-incidents-card">
            <div class="card-head">
                <div>
                    <h2>Open incidents</h2>
                    <p>Alerts requiring attention.</p>
                </div>
                <span class="table-count">{{ $incidents->count() }}</span>
            </div>
            @forelse($incidents as $incident)
                <a href="{{ route('alerts.index') }}" class="monitoring-incident-row">
                    <span class="incident-icon {{ $incident->severity }}">
                        <i data-lucide="triangle-alert"></i>
                    </span>
                    <span>
                        <strong>{{ $incident->rule->name }}</strong>
                        <small>{{ $incident->message }} · {{ $incident->triggered_at->diffForHumans() }}</small>
                    </span>
                    <i data-lucide="chevron-right"></i>
                </a>
            @empty
                <div class="healthy-empty">
                    <i data-lucide="shield-check"></i>
                    <strong>All systems healthy</strong>
                    <small>No open incidents for this scope.</small>
                </div>
            @endforelse
        </aside>
    </div>

    <section class="card monitoring-health-card">
        <div class="card-head">
            <div>
                <h2>Server health</h2>
                <p>Latest resource snapshot and network throughput.</p>
            </div>
        </div>
        <div class="monitoring-table-scroll">
            <div class="monitoring-table">
                <div class="monitoring-table-head">
                    <span>Server</span>
                    <span>CPU</span>
                    <span>Memory</span>
                    <span>Disk</span>
                    <span>Load</span>
                    <span>Network</span>
                    <span>Status</span>
                </div>
                @forelse($servers as $server)
                    @php($metric = $server->metrics->last())
                    <div class="monitoring-table-row">
                        <span class="resource-primary">
                            <span class="server-icon"><i data-lucide="server"></i></span>
                            <span>
                                <strong>{{ $server->name }}</strong>
                                <small>{{ $server->location ?: $server->ip_address }}</small>
                            </span>
                        </span>
                        @foreach(['cpu_percent', 'memory_percent', 'disk_percent'] as $field)
                            <span class="metric-cell">
                                <strong>{{ round($metric?->$field ?? 0, 1) }}%</strong>
                                <i><b style="width:{{ min(100, $metric?->$field ?? 0) }}%"></b></i>
                            </span>
                        @endforeach
                        <span>{{ $metric?->load_average ?? '—' }}</span>
                        <span>
                            <strong>{{ number_format((($metric?->network_in_bytes ?? 0) + ($metric?->network_out_bytes ?? 0)) / 1048576, 1) }} MB</strong>
                            <small>in + out</small>
                        </span>
                        <span>
                            <em class="status status--{{ $server->status->value === 'online' ? 'success' : 'failed' }}">
                                <i></i>{{ ucfirst($server->status->value) }}
                            </em>
                        </span>
                    </div>
                @empty
                    <div class="monitoring-empty-row">No servers match the current filters.</div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="card monitoring-processes-card">
        <div class="card-head">
            <div>
                <h2>Top processes</h2>
                <p>Highest current container resource consumption.</p>
            </div>
            <a href="{{ route('containers.index', array_filter(['server' => $serverId, 'application' => $siteId])) }}">View all</a>
        </div>
        <div class="monitoring-process-grid">
            @forelse($topContainers as $container)
                <article>
                    <span class="rank">{{ $loop->iteration }}</span>
                    <span class="server-icon"><i data-lucide="box"></i></span>
                    <span>
                        <strong>{{ $container->name }}</strong>
                        <small>
                            {{ $container->deployment?->domain ?: ($container->deployment?->name ?? ($container->server?->name ?? 'Server removed')) }}
                        </small>
                    </span>
                    <div>
                        <small>CPU</small>
                        <strong>{{ $container->cpu_percent }}%</strong>
                    </div>
                    <div>
                        <small>Memory</small>
                        <strong>{{ $container->memory_usage_mb }} MB</strong>
                    </div>
                    <em class="status status--{{ $container->health === 'healthy' ? 'success' : 'warning' }}">
                        <i></i>{{ ucfirst($container->health ?? $container->status->value) }}
                    </em>
                </article>
            @empty
                <div class="monitoring-empty-copy">No containers found for the selected server or site.</div>
            @endforelse
        </div>
    </section>
</div>
</x-dashboard-layout>
