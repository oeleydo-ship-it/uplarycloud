@php
    $cpuValue = $latestMetric ? round((float) $latestMetric->cpu_percent).'%' : '—';
    $memoryValue = $latestMetric ? round((float) $latestMetric->memory_percent).'%' : '—';
    $diskValue = $latestMetric ? round((float) $latestMetric->disk_percent).'%' : '—';
    $cpuCores = (int) ($server->cpu_cores ?? 0);
    $memoryMb = (int) ($server->memory_mb ?? 0);
    $diskGb = (int) ($server->disk_gb ?? 0);
    $cpuDetail = $latestMetric
        ? trim(($cpuCores > 0 ? $cpuCores.' vCPU · ' : '').'load '.number_format((float) $latestMetric->load_average, 2))
        : ($cpuCores > 0 ? $cpuCores.' vCPU · Collecting…' : 'Detecting…');
    $memoryDetail = $memoryMb > 0
        ? number_format($memoryMb / 1024, $memoryMb >= 1024 ? 0 : 1).' GB installed'.($latestMetric ? '' : ' · Collecting…')
        : 'Detecting…';
    $diskDetail = $diskGb > 0
        ? $diskGb.' GB total'.($latestMetric ? '' : ' · Collecting…')
        : 'Detecting…';
    $cpuSpark = $metricHistory->take(-5)->values();
    $memorySpark = $metricHistory->take(-5)->values();
    $sshEndpoint = $server->ssh_username.'@'.$server->ip_address.':'.$server->ssh_port;
    $serverFilter = ['server' => $server->id];
    $tags = collect($server->tags ?? [])->filter()->values();
    $awaitingMetrics = session('metrics_refresh') && ! $latestMetric;
@endphp
<x-dashboard-layout :title="$server->name">
    <div class="server-detail-page" @if($awaitingMetrics) x-data x-init="setTimeout(() => window.location.reload(), 4000)" @endif>
        <div class="server-detail-heading">
            <div class="server-title">
                <span class="server-icon server-icon--large"><i data-lucide="server"></i></span>
                <div>
                    <p class="breadcrumb"><a href="{{ route('servers.index') }}">Servers</a> <i data-lucide="chevron-right"></i> {{ $server->name }}</p>
                    <h1>{{ $server->name }}</h1>
                    <p class="server-title-meta">
                        <span class="status status--{{ $server->status->tone() }}"><i></i>{{ $server->status->label() }}</span>
                        <span class="mono">{{ $server->ip_address }}</span>
                        @if($server->location)
                            <span>{{ $server->location }}</span>
                        @endif
                    </p>
                </div>
            </div>
            <div class="heading-actions">
                @can('operate', $server)
                    <form method="POST" action="{{ route('servers.refresh', $server) }}">
                        @csrf
                        <button class="button button--secondary" type="submit"><i data-lucide="refresh-cw"></i> Refresh</button>
                    </form>
                @else
                    <a href="{{ route('servers.details', $server) }}" class="button button--secondary"><i data-lucide="refresh-cw"></i> Refresh</a>
                @endcan
                <a href="{{ route('applications.index', $serverFilter) }}" class="button button--primary"><i data-lucide="rocket"></i> Deploy application</a>
                <details class="server-more">
                    <summary class="icon-button more-button" aria-label="More actions"><i data-lucide="more-horizontal"></i></summary>
                    <div class="server-more-menu">
                        <a href="{{ route('servers.details', $server) }}#settings"><i data-lucide="settings"></i> Server settings</a>
                        <a href="{{ route('monitoring.index', $serverFilter) }}"><i data-lucide="activity"></i> Open monitoring</a>
                        <a href="{{ route('logs.index', ['server_id' => $server->id]) }}"><i data-lucide="scroll-text"></i> View logs</a>
                        @can('delete', $server)
                            @if($server->applicationDeployments()->exists())
                                <a href="{{ route('applications.installed', $serverFilter) }}"><i data-lucide="blocks"></i> Remove applications first</a>
                            @else
                                <form method="POST" action="{{ route('servers.destroy', $server) }}" onsubmit="return confirm('Remove {{ $server->name }} from the control plane? Remote data is not deleted.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="is-danger"><i data-lucide="trash-2"></i> Destroy server</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                </details>
            </div>
        </div>

        <nav class="detail-tabs" aria-label="Server sections">
            <a href="{{ route('servers.details', $server) }}" class="is-active">Overview</a>
            <a href="{{ route('applications.installed', $serverFilter) }}">Applications</a>
            <a href="{{ route('containers.index', $serverFilter) }}">Containers</a>
            <a href="{{ route('domains.index', $serverFilter) }}">Domains</a>
            <a href="{{ route('volumes.index', $serverFilter) }}">Volumes</a>
            <a href="{{ route('monitoring.index', $serverFilter) }}">Monitoring</a>
            <a href="{{ route('logs.index', ['server_id' => $server->id]) }}">Logs</a>
            <a href="{{ route('images.index', $serverFilter) }}">Images</a>
            <a href="{{ route('servers.details', $server) }}#settings">Settings</a>
        </nav>

        <section class="stats-grid detail-stats">
            <article class="stat-card mini-metric">
                <span class="stat-icon stat-icon--purple"><i data-lucide="cpu"></i></span>
                <div>
                    <span class="stat-label">CPU usage</span>
                    <strong class="stat-value {{ $latestMetric ? '' : 'is-collecting' }}">{{ $cpuValue }}</strong>
                    <small class="{{ $latestMetric ? '' : 'is-collecting' }}">{{ $cpuDetail }}</small>
                </div>
                <div class="spark">
                    @forelse($cpuSpark as $point)
                        <i style="height:{{ max(8, min(100, (float) $point->cpu_percent)) }}%"></i>
                    @empty
                        <i style="height:8%"></i><i style="height:8%"></i><i style="height:8%"></i><i style="height:8%"></i><i style="height:8%"></i>
                    @endforelse
                </div>
            </article>
            <article class="stat-card mini-metric">
                <span class="stat-icon stat-icon--blue"><i data-lucide="memory-stick"></i></span>
                <div>
                    <span class="stat-label">Memory</span>
                    <strong class="stat-value {{ $latestMetric ? '' : 'is-collecting' }}">{{ $memoryValue }}</strong>
                    <small class="{{ $latestMetric ? '' : 'is-collecting' }}">{{ $memoryDetail }}</small>
                </div>
                <div class="spark blue">
                    @forelse($memorySpark as $point)
                        <i style="height:{{ max(8, min(100, (float) $point->memory_percent)) }}%"></i>
                    @empty
                        <i style="height:8%"></i><i style="height:8%"></i><i style="height:8%"></i><i style="height:8%"></i><i style="height:8%"></i>
                    @endforelse
                </div>
            </article>
            <article class="stat-card mini-metric">
                <span class="stat-icon stat-icon--orange"><i data-lucide="hard-drive"></i></span>
                <div>
                    <span class="stat-label">Disk</span>
                    <strong class="stat-value {{ $latestMetric ? '' : 'is-collecting' }}">{{ $diskValue }}</strong>
                    <small class="{{ $latestMetric ? '' : 'is-collecting' }}">{{ $diskDetail }}</small>
                </div>
            </article>
            <article class="stat-card mini-metric">
                <span class="stat-icon stat-icon--green"><i data-lucide="clock-3"></i></span>
                <div>
                    <span class="stat-label">Uptime</span>
                    <strong class="stat-value">{{ $uptimeLabel }}</strong>
                    <small>Last seen {{ $server->last_seen_at?->diffForHumans() ?: 'never' }}</small>
                </div>
            </article>
        </section>

        <div class="server-overview-grid">
            <article class="card resource-card">
                <div class="card-head">
                    <div>
                        <h2>Resource usage</h2>
                        <p>{{ $chart['has_data'] ? 'CPU and memory from recent collections' : 'Waiting for the first metric sample' }}</p>
                    </div>
                    <a href="{{ route('monitoring.index', array_merge($serverFilter, ['range' => '24h'])) }}" class="filter-button">24H <i data-lucide="chevron-right"></i></a>
                </div>
                <div class="line-chart detail-chart {{ $chart['has_data'] ? '' : 'is-empty' }}">
                    @if($chart['has_data'])
                        <div class="chart-grid"></div>
                        <svg viewBox="0 0 600 180" preserveAspectRatio="none" role="img" aria-label="CPU and memory usage chart">
                            <polyline class="chart-line chart-line--purple" fill="none" points="{{ $chart['cpu_points'] }}" />
                            <polyline class="chart-line chart-line--green" fill="none" points="{{ $chart['memory_points'] }}" />
                        </svg>
                        <div class="chart-axis">
                            @foreach($chart['axis'] as $label)
                                <span>{{ $label }}</span>
                            @endforeach
                        </div>
                    @else
                        <div class="chart-empty">
                            <i data-lucide="activity"></i>
                            <strong>No metrics yet</strong>
                            <p>Refresh to queue collection, or wait for the scheduled monitor. A queue worker must process the <code>monitoring</code> queue.</p>
                        </div>
                    @endif
                </div>
            </article>
            <article class="card system-card">
                <div class="card-head">
                    <div>
                        <h2>System information</h2>
                        <p>Host and Docker runtime</p>
                    </div>
                </div>
                <dl>
                    <div>
                        <dt>Operating system</dt>
                        <dd>{{ $server->operating_system ? str($server->operating_system)->replace('-', ' ')->title() : '—' }}</dd>
                    </div>
                    <div>
                        <dt>Docker Engine</dt>
                        <dd>{{ $server->docker_version ?: 'Not detected' }}</dd>
                    </div>
                    <div>
                        <dt>Docker Compose</dt>
                        <dd>{{ $server->docker_compose_version ?: 'Not detected' }}</dd>
                    </div>
                    <div>
                        <dt>SSH endpoint</dt>
                        <dd class="mono">{{ $sshEndpoint }}</dd>
                    </div>
                    <div>
                        <dt>Provisioned</dt>
                        <dd>{{ $server->provisioned_at?->format('M j, Y H:i') ?: 'Pending' }}</dd>
                    </div>
                    <div>
                        <dt>Last seen</dt>
                        <dd>{{ $server->last_seen_at?->format('M j, Y H:i') ?: 'Never' }}</dd>
                    </div>
                </dl>
            </article>
        </div>

        <article class="card services-card">
            <div class="card-head">
                <div>
                    <h2>Platform services</h2>
                    <p>Installed components and health</p>
                </div>
                <a href="{{ route('logs.index', ['server_id' => $server->id]) }}">View logs</a>
            </div>
            <div class="services-grid">
                @foreach($platformServices as $service)
                    <div>
                        <span class="quick-icon {{ $service['icon_tone'] }}"><i data-lucide="{{ $service['icon'] }}"></i></span>
                        <span>
                            <strong>{{ $service['name'] }}</strong>
                            <small>{{ $service['detail'] }}</small>
                        </span>
                        <span class="status status--{{ $service['tone'] }}"><i></i> {{ $service['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </article>

        <article id="settings" class="card settings-card">
            <div class="card-head">
                <div>
                    <h2>Settings</h2>
                    <p>Connection and install preferences for this server</p>
                </div>
            </div>
            <dl class="settings-facts">
                <div>
                    <dt>SSH endpoint</dt>
                    <dd class="mono">{{ $sshEndpoint }}</dd>
                </div>
                <div>
                    <dt>Authentication</dt>
                    <dd>{{ $server->authentication_method->label() }}</dd>
                </div>
                <div>
                    <dt>Tags</dt>
                    <dd>
                        @if($tags->isNotEmpty())
                            <span class="settings-tag-list">
                                @foreach($tags as $tag)
                                    <b>{{ $tag }}</b>
                                @endforeach
                            </span>
                        @else
                            None
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>Infrastructure</dt>
                    <dd>{{ $server->isManaged() ? 'Managed server' : $server->provider->label() }}</dd>
                </div>
                <div>
                    <dt>Install flags</dt>
                    <dd>
                        <span class="settings-flags">
                            <span class="settings-flag {{ $server->install_docker ? 'is-on' : '' }}"><i data-lucide="{{ $server->install_docker ? 'check' : 'minus' }}"></i> Docker</span>
                            <span class="settings-flag {{ $server->install_proxy ? 'is-on' : '' }}"><i data-lucide="{{ $server->install_proxy ? 'check' : 'minus' }}"></i> Proxy</span>
                            <span class="settings-flag {{ $server->install_monitoring ? 'is-on' : '' }}"><i data-lucide="{{ $server->install_monitoring ? 'check' : 'minus' }}"></i> Monitoring</span>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Connection timeout</dt>
                    <dd>{{ $server->connection_timeout }}s</dd>
                </div>
            </dl>
            <div class="settings-actions">
                <a href="{{ route('containers.index', $serverFilter) }}" class="button button--secondary"><i data-lucide="box"></i> Manage containers</a>
                <a href="{{ route('applications.installed', $serverFilter) }}" class="button button--secondary"><i data-lucide="blocks"></i> Manage applications</a>
                @can('operate', $server)
                    <form method="POST" action="{{ route('servers.refresh', $server) }}">
                        @csrf
                        <button class="button button--secondary" type="submit"><i data-lucide="refresh-cw"></i> Refresh metrics</button>
                    </form>
                @endcan
            </div>
        </article>

        @if($server->isManaged())
            <article class="card managed-control-card">
                <div class="card-head">
                    <div>
                        <h2>Managed lifecycle</h2>
                        <p>Managed server · {{ $server->managedPlan?->name }} · {{ $server->managedPlan?->priceLabel() }}/month</p>
                    </div>
                    <a href="{{ route('managed.index') }}">Managed cloud</a>
                </div>
                <div class="managed-control-grid">
                    <section>
                        <h3>Safe operations</h3>
                        <p>Synchronize managed-server state or restart the instance without changing disks.</p>
                        <div class="managed-control-actions">
                            <form method="POST" action="{{ route('managed.servers.action', $server) }}">@csrf<input type="hidden" name="action" value="sync"><button class="button button--secondary"><i data-lucide="refresh-cw"></i>Sync</button></form>
                            <form method="POST" action="{{ route('managed.servers.action', $server) }}">@csrf<input type="hidden" name="action" value="restart"><button class="button button--secondary" onclick="return confirm('Restart this cloud instance?')"><i data-lucide="rotate-cw"></i>Restart</button></form>
                        </div>
                    </section>
                    <section>
                        <h3>Resize compute</h3>
                        <p>Upgrade CPU, memory, and disk. A prorated infrastructure adjustment may apply.</p>
                        <form method="POST" action="{{ route('managed.servers.action', $server) }}" class="managed-control-actions">
                            @csrf
                            <input type="hidden" name="action" value="resize">
                            <select name="managed_server_plan_id">
                                @foreach(\App\Models\ManagedServerPlan::where('provider', $server->provider->value)->where('active', true)->get() as $plan)
                                    <option value="{{ $plan->id }}" @selected($plan->id === $server->managed_server_plan_id)>{{ $plan->name }} · {{ $plan->priceLabel() }}</option>
                                @endforeach
                            </select>
                            <button class="button button--secondary" onclick="return confirm('Resize this server?')">Resize</button>
                        </form>
                    </section>
                    <section>
                        <h3>Rebuild or destroy</h3>
                        <p>Rebuild replaces the operating system. Destroy permanently removes the cloud instance.</p>
                        <div class="managed-control-actions">
                            <form method="POST" action="{{ route('managed.servers.action', $server) }}">
                                @csrf
                                <input type="hidden" name="action" value="rebuild">
                                <select name="image">
                                    @foreach($server->managedPlan?->images ?? [] as $image)
                                        <option>{{ $image }}</option>
                                    @endforeach
                                </select>
                                <button class="button button--secondary" onclick="return confirm('Rebuild and replace the server operating system?')">Rebuild</button>
                            </form>
                            <form method="POST" action="{{ route('managed.servers.action', $server) }}">
                                @csrf
                                <input type="hidden" name="action" value="destroy">
                                <button class="button button--danger" onclick="return confirm('Permanently destroy this managed cloud instance?')"><i data-lucide="trash-2"></i>Destroy</button>
                            </form>
                        </div>
                    </section>
                </div>
            </article>
        @endif
    </div>
</x-dashboard-layout>
