@php($liveTail = request()->boolean('live'))
@php($selectedServer = (string) (request('server_id') ?: request('server') ?: ''))
<x-dashboard-layout title="Logs">
    <div
        class="logs-page"
        x-data="{
            liveTail: {{ $liveTail ? 'true' : 'false' }},
            paused: false,
            autoScroll: true,
            scrollFeed() {
                if (!this.autoScroll) return;
                const feed = this.$refs.feed;
                if (feed) feed.scrollTop = feed.scrollHeight;
            }
        }"
        x-init="
            scrollFeed();
            setInterval(() => { if (liveTail && !paused) window.location.reload(); }, 5000);
            $watch('autoScroll', (on) => { if (on) scrollFeed(); });
        "
    >
        <div class="page-heading logs-reference-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <i data-lucide="chevron-right"></i>
                    Logs
                </p>
                <h1>Logs <i data-lucide="info"></i></h1>
                <p>View and search logs from your servers, apps, and system events.</p>
            </div>
            <div class="heading-actions">
                <button type="button" class="button button--secondary" disabled title="Coming soon">
                    <i data-lucide="settings-2"></i> Log Settings
                </button>
                <x-plan-locked-action feature="audit_exports" label="log exports">
                    <a href="{{ route('logs.download', request()->query()) }}" class="button button--primary">
                        <i data-lucide="download"></i> Export Logs
                    </a>
                </x-plan-locked-action>
            </div>
        </div>

        <section class="stats-grid logs-reference-stats">
            <x-stat-card label="Total Log Files" :value="$counts['all']" detail="Current retention" icon="scroll-text" tone="purple"/>
            <x-stat-card label="Total Size" :value="number_format($counts['size'] / 1048576, 1).' MB'" detail="Approx. from message length" icon="database" tone="blue"/>
            <x-stat-card label="Errors (24h)" :value="$counts['errors']" detail="Error and critical" icon="circle-x" tone="red"/>
            <x-stat-card label="Warnings (24h)" :value="$counts['warnings']" detail="Warnings only" icon="triangle-alert" tone="orange"/>
        </section>

        <div class="logs-reference-layout">
            <main class="logs-reference-main">
                <section class="card logs-feed-card">
                    <form method="get" class="logs-reference-filters" action="{{ route('logs.index') }}">
                        @if(request()->filled('severity'))
                            <input type="hidden" name="severity" value="{{ request('severity') }}">
                        @endif

                        <label class="table-search">
                            <i data-lucide="search"></i>
                            <input name="search" value="{{ request('search') }}" placeholder="Search logs...">
                        </label>

                        <label class="filter-select">
                            <select name="type" onchange="this.form.submit()">
                                <option value="">All Types</option>
                                @foreach(['application','container','deployment','server','backup','system'] as $t)
                                    <option value="{{ $t }}" @selected(request('type', request('category'))===$t)>{{ ucfirst($t) }}</option>
                                @endforeach
                            </select>
                            <i data-lucide="chevron-down"></i>
                        </label>

                        <label class="filter-select">
                            <select name="source" onchange="this.form.submit()">
                                <option value="">All Sources</option>
                                @foreach($sources as $src)
                                    <option value="{{ $src['source'] }}" @selected(request('source')===$src['source'])>{{ $src['source'] }}</option>
                                @endforeach
                            </select>
                            <i data-lucide="chevron-down"></i>
                        </label>

                        <label class="filter-select">
                            <select name="server" onchange="this.form.submit()">
                                <option value="">All Servers</option>
                                @foreach($servers as $server)
                                    <option value="{{ $server->id }}" @selected($selectedServer===(string) $server->id)>{{ $server->name }}</option>
                                @endforeach
                            </select>
                            <i data-lucide="chevron-down"></i>
                        </label>

                        <label class="filter-select">
                            <select name="site" onchange="this.form.submit()">
                                <option value="">All Sites</option>
                                @foreach($sites as $site)
                                    <option value="{{ $site->id }}" @selected((string) request('site')===(string) $site->id)>
                                        {{ $site->domain ?: $site->name }} · {{ $site->server?->name ?? 'Server removed' }}
                                    </option>
                                @endforeach
                            </select>
                            <i data-lucide="chevron-down"></i>
                        </label>

                        <label class="filter-select">
                            <select name="application" onchange="this.form.submit()">
                                <option value="">All Apps</option>
                                @foreach($applications as $application)
                                    <option value="{{ $application->id }}" @selected((string) request('application')===(string) $application->id)>{{ $application->name }}</option>
                                @endforeach
                            </select>
                            <i data-lucide="chevron-down"></i>
                        </label>

                        <label class="filter-select">
                            <select name="range" onchange="this.form.submit()">
                                @foreach(['1h'=>'Last 1 Hour','6h'=>'Last 6 Hours','24h'=>'Last 24 Hours','7d'=>'Last 7 Days','30d'=>'Last 30 Days'] as $key=>$label)
                                    <option value="{{ $key }}" @selected(request('range', '1h')===$key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <i data-lucide="chevron-down"></i>
                        </label>
                    </form>

                    <div class="logs-reference-controls">
                        <form method="get" class="logs-controls-form" action="{{ route('logs.index') }}">
                            @foreach(request()->except('live') as $key => $value)
                                @if(is_scalar($value))
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endif
                            @endforeach
                            <label class="toggle-field" title="Live tail reloads the page every 5s">
                                <input
                                    type="checkbox"
                                    name="live"
                                    value="1"
                                    x-model="liveTail"
                                    @change="$el.form.requestSubmit()"
                                >
                                <span>Live Tail</span>
                            </label>
                            <button type="submit" class="button button--secondary logs-refresh-btn">
                                <i data-lucide="refresh-cw"></i> Refresh
                            </button>
                        </form>

                        <div class="logs-feed-controls">
                            <span class="live-pill" x-show="liveTail" x-cloak>
                                <i></i>
                                <span x-text="paused ? 'Paused' : 'Live'"></span>
                            </span>
                            <label class="autoscroll-toggle">
                                <input type="checkbox" x-model="autoScroll">
                                Auto scroll
                            </label>
                            <button type="button" class="button button--secondary" @click="paused = !paused" :disabled="!liveTail">
                                <i data-lucide="pause" x-show="!paused"></i>
                                <i data-lucide="play" x-show="paused" x-cloak></i>
                                <span x-text="paused ? 'Resume' : 'Pause'"></span>
                            </button>
                        </div>
                    </div>

                    <div class="logs-reference-feed-head">
                        <span class="logs-feed-scope">
                            Showing logs from
                            <strong>{{ $contextServer?->name ?? 'Workspace' }}</strong>
                        </span>
                    </div>

                    <div class="logs-reference-feed" x-ref="feed">
                        <div class="logs-reference-head-row">
                            <span>#</span><span>Time</span><span>Level</span><span>Source</span><span>Message</span><span></span>
                        </div>
                        @forelse($logs as $log)
                            @php($idx = ($logs->currentPage() - 1) * $logs->perPage() + $loop->iteration)
                            <div class="logs-log-row">
                                <span class="logs-row-index">{{ $idx }}</span>
                                <time>{{ $log->occurred_at->format('M d H:i:s') }}</time>
                                <b class="logs-level logs-level--{{ $log->severity }}">{{ strtoupper($log->severity) }}</b>
                                <code>{{ $log->source ?? 'control-plane' }}</code>
                                <p>{{ $log->message }}</p>
                                <button type="button" class="icon-button" aria-label="Copy message" title="Copy" onclick="navigator.clipboard.writeText(@js($log->message))">
                                    <i data-lucide="copy"></i>
                                </button>
                            </div>
                        @empty
                            <div class="logs-empty">
                                <span class="logs-empty-icon"><i data-lucide="scroll-text"></i></span>
                                <strong>No log events found</strong>
                                <p>No events match the current filters. Broaden the time range, clear a filter, or wait for the next operation.</p>
                            </div>
                        @endforelse
                    </div>

                    <div class="logs-reference-footer">
                        <div>
                            Showing
                            <strong>{{ $logs->firstItem() ?? 0 }}</strong>
                            to
                            <strong>{{ $logs->lastItem() ?? 0 }}</strong>
                            of
                            <strong>{{ $logs->total() }}</strong>
                            entries
                        </div>
                        <div class="logs-footer-controls">
                            <form method="get" class="logs-per-page" action="{{ route('logs.index') }}">
                                @foreach(request()->except('per_page', 'page') as $k => $v)
                                    @if(is_scalar($v))
                                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                                    @endif
                                @endforeach
                                <select name="per_page" onchange="this.form.submit()">
                                    @foreach([25, 50, 100] as $p)
                                        <option value="{{ $p }}" @selected((int) request('per_page', 50) === $p)>{{ $p }}/page</option>
                                    @endforeach
                                </select>
                            </form>
                            {{ $logs->links() }}
                        </div>
                    </div>
                </section>
            </main>

            <aside class="logs-reference-aside">
                <section class="card logs-sources-card">
                    <div class="card-head">
                        <div>
                            <h2>Log Sources</h2>
                            <p>Counts across your selected time range.</p>
                        </div>
                        <a href="{{ route('logs.index', collect(request()->query())->except('source')->all()) }}" class="icon-button" title="Clear source">
                            <i data-lucide="x"></i>
                        </a>
                    </div>

                    <div class="logs-sources-list">
                        @forelse($sources as $src)
                            <a class="logs-source-row {{ request('source') === $src['source'] ? 'is-active' : '' }}" href="{{ route('logs.index', array_merge(request()->query(), ['source' => $src['source']])) }}">
                                <span class="logs-source-name">{{ $src['source'] }}</span>
                                <em class="logs-source-count">{{ $src['total'] }}</em>
                            </a>
                        @empty
                            <p class="empty-copy">No sources in this scope.</p>
                        @endforelse
                    </div>

                    <div class="logs-quick-access-divider"></div>
                    <a class="logs-quick-access-link" href="{{ route('logs.index', array_merge(request()->query(), ['severity' => 'error'])) }}">
                        <span class="logs-quick-access-icon"><i data-lucide="circle-x"></i></span>
                        <span><strong>Error Logs</strong><small>Errors and critical events</small></span>
                    </a>
                    <a class="logs-quick-access-link" href="{{ route('logs.index', array_merge(request()->query(), ['severity' => 'warning'])) }}">
                        <span class="logs-quick-access-icon"><i data-lucide="triangle-alert"></i></span>
                        <span><strong>Warning Logs</strong><small>Warnings only</small></span>
                    </a>
                </section>

                <section class="card logs-quick-card">
                    <div class="card-head">
                        <div>
                            <h2>Quick Access</h2>
                            <p>Common log categories.</p>
                        </div>
                    </div>
                    <div class="logs-quick-grid">
                        <a class="logs-quick-tile" href="{{ route('logs.index', array_merge(request()->query(), ['type' => 'server'])) }}">
                            <span class="logs-quick-tile-icon"><i data-lucide="server"></i></span>
                            <span><strong>Server</strong><small>Infrastructure events</small></span>
                        </a>
                        <a class="logs-quick-tile" href="{{ route('logs.index', array_merge(request()->query(), ['type' => 'application'])) }}">
                            <span class="logs-quick-tile-icon"><i data-lucide="rocket"></i></span>
                            <span><strong>Applications</strong><small>App &amp; framework logs</small></span>
                        </a>
                        <a class="logs-quick-tile" href="{{ route('logs.index', array_merge(request()->query(), ['type' => 'system'])) }}">
                            <span class="logs-quick-tile-icon"><i data-lucide="shield-check"></i></span>
                            <span><strong>System</strong><small>Control plane</small></span>
                        </a>
                        <a class="logs-quick-tile" href="{{ route('logs.index', array_merge(request()->query(), ['type' => 'backup'])) }}">
                            <span class="logs-quick-tile-icon"><i data-lucide="archive"></i></span>
                            <span><strong>Backups</strong><small>Recovery jobs</small></span>
                        </a>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-dashboard-layout>
