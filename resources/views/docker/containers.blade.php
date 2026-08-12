<x-dashboard-layout title="Containers">
    <div class="containers-page">
        <div class="page-heading containers-reference-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <i data-lucide="chevron-right"></i>
                    Containers
                </p>
                <h1>Containers <i data-lucide="info"></i></h1>
                <p>Manage your containers running on {{ $contextServer?->name ?? 'connected servers' }}.</p>
            </div>
            <div class="heading-actions">
                <form method="POST" action="{{ route('containers.prune') }}">
                    @csrf
                    @if(request('server'))
                        <input type="hidden" name="server" value="{{ request('server') }}">
                    @endif
                    <button type="submit" class="button button--secondary" onclick="return confirm('Remove all stopped containers on the selected servers?')">
                        <i data-lucide="trash-2"></i> Prune
                    </button>
                </form>
                <form method="POST" action="{{ route('containers.sync') }}">
                    @csrf
                    @if(request('server'))
                        <input type="hidden" name="server" value="{{ request('server') }}">
                    @endif
                    <button type="submit" class="button button--secondary">
                        <i data-lucide="refresh-cw"></i> Sync
                    </button>
                </form>
                <a href="{{ route('compose.index') }}" class="button button--primary"><i data-lucide="plus"></i> Run Container</a>
            </div>
        </div>

        <section class="stats-grid containers-reference-stats">
            <x-stat-card label="Total Containers" :value="$counts['all']" :detail="$counts['running'].' Running'" icon="box" tone="purple" :href="route('containers.index')" />
            <x-stat-card label="Running" :value="$counts['running']" detail="Healthy workloads" icon="circle-play" tone="green" :href="route('containers.index', ['status' => 'running'])" />
            <x-stat-card label="Stopped" :value="$counts['stopped']" detail="Click to start" icon="square" tone="orange" :href="route('containers.index', ['status' => 'stopped'])" />
            <x-stat-card label="Restarting" :value="$counts['restarting']" detail="In progress" icon="refresh-cw" tone="blue" :href="route('containers.index', ['status' => 'restarting'])" />
        </section>

        <form class="containers-reference-filters" method="get">
            <label class="table-search">
                <i data-lucide="search"></i>
                <input name="search" value="{{ request('search') }}" placeholder="Search containers...">
            </label>
            <label class="filter-select">
                <select name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    @foreach(\App\Enums\ContainerStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down"></i>
            </label>
            <label class="filter-select">
                <select name="application" onchange="this.form.submit()">
                    <option value="">All Applications</option>
                    @foreach($applications as $application)
                        <option value="{{ $application->id }}" @selected((string) request('application') === (string) $application->id)>{{ $application->name }}</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down"></i>
            </label>
            <label class="filter-select">
                <select name="server" onchange="this.form.submit()">
                    <option value="">All Servers</option>
                    @foreach($servers as $server)
                        <option value="{{ $server->id }}" @selected((string) request('server') === (string) $server->id)>{{ $server->name }}</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down"></i>
            </label>
            <label class="filter-select filter-select--sort">
                <select name="sort" onchange="this.form.submit()">
                    <option value="newest">Newest First</option>
                    <option value="oldest" @selected(request('sort') === 'oldest')>Oldest First</option>
                    <option value="name" @selected(request('sort') === 'name')>Name</option>
                </select>
                <i data-lucide="arrow-up-down"></i>
            </label>
        </form>

        <article class="card containers-reference-table-card">
            @if($containers->isEmpty())
                <div class="empty-state">
                    <span><i data-lucide="box"></i></span>
                    <h2>No containers found</h2>
                    <p>Deploy an application or run a Compose project to create containers.</p>
                    <a href="{{ route('applications.index') }}" class="button button--primary"><i data-lucide="plus"></i> Deploy application</a>
                </div>
            @else
                <div class="containers-reference-table">
                    <div class="containers-reference-head">
                        <span>Container Name</span>
                        <span>Application</span>
                        <span>Image</span>
                        <span>Status</span>
                        <span>Uptime</span>
                        <span>CPU</span>
                        <span>Memory</span>
                        <span>Ports</span>
                        <span>Actions</span>
                    </div>
                    @foreach($containers as $container)
                        @php
                            $application = $container->resolvedApplication();
                            $cpuPercent = min(100, (float) $container->cpu_percent);
                            $memoryPercent = $container->memoryPercent();
                            $operable = $container->isOperable();
                            $canStart = $container->canStart();
                            $canStop = $container->canStop();
                            $canRestart = $container->canRestart();
                        @endphp
                        <div class="containers-reference-row">
                            <span class="containers-reference-primary">
                                <span class="containers-icon"><i data-lucide="box"></i></span>
                                <span>
                                    <strong>{{ $container->name }}</strong>
                                    <small class="mono">{{ $container->docker_id ?: $container->uuid }}</small>
                                    <small>{{ $container->server?->name ?? 'Server removed' }}</small>
                                </span>
                            </span>
                            <span class="containers-application">
                                <x-application-icon :application="$application" size="sm" />
                                <span>
                                    <strong>{{ $container->applicationLabel() }}</strong>
                                    @if($container->versionLabel())
                                        <small class="containers-version">{{ $container->versionLabel() }}</small>
                                    @endif
                                    @if($container->deployment)
                                        <small><a href="{{ route('deployments.show', $container->deployment) }}">View deployment</a></small>
                                    @endif
                                </span>
                            </span>
                            <span class="mono"><strong>{{ $container->image }}</strong></span>
                            <span>
                                <em class="containers-status-badge containers-status-badge--{{ $container->status->tone() }}"><i></i>{{ $container->status->label() }}</em>
                            </span>
                            <span><strong>{{ $container->uptimeLabel() }}</strong></span>
                            <span class="containers-metric">
                                <strong>{{ number_format($cpuPercent, 1) }}%</strong>
                                <div class="metric-track"><i style="width: {{ $cpuPercent }}%"></i></div>
                            </span>
                            <span class="containers-metric">
                                <strong>{{ $container->memoryLabel() }}</strong>
                                @if($container->hasMemoryLimit())
                                    <div class="metric-track"><i style="width: {{ $memoryPercent }}%"></i></div>
                                @endif
                            </span>
                            <span class="containers-ports">
                                @if($container->formattedPorts() === 'Internal')
                                    <strong>Internal</strong>
                                @else
                                    @foreach(explode(', ', $container->formattedPorts()) as $port)
                                        <a href="#">{{ $port }}</a>
                                    @endforeach
                                @endif
                            </span>
                            <span class="containers-actions">
                                @if($canStart)
                                    <form method="POST" action="{{ route('containers.action', $container) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="start">
                                        <button type="submit" class="icon-button" aria-label="Start {{ $container->name }}"><i data-lucide="play"></i></button>
                                    </form>
                                @endif
                                @if($canStop)
                                    <form method="POST" action="{{ route('containers.action', $container) }}" onsubmit="return confirm('Stop {{ $container->name }}?')">
                                        @csrf
                                        <input type="hidden" name="action" value="stop">
                                        <button type="submit" class="icon-button" aria-label="Stop {{ $container->name }}"><i data-lucide="square"></i></button>
                                    </form>
                                @endif
                                @if($canRestart)
                                    <form method="POST" action="{{ route('containers.action', $container) }}" onsubmit="return confirm('Restart {{ $container->name }}?')">
                                        @csrf
                                        <input type="hidden" name="action" value="restart">
                                        <button type="submit" class="icon-button" aria-label="Restart {{ $container->name }}"><i data-lucide="refresh-cw"></i></button>
                                    </form>
                                @endif
                                <details class="containers-more">
                                    <summary class="icon-button" aria-label="More actions"><i data-lucide="ellipsis"></i></summary>
                                    <div class="containers-more-menu">
                                        @if($container->server && ! $container->server->trashed())
                                            <a href="{{ route('servers.show', $container->server) }}"><i data-lucide="server"></i> View server</a>
                                        @endif
                                        @if($container->deployment)
                                            <a href="{{ route('deployments.show', $container->deployment) }}"><i data-lucide="external-link"></i> View application</a>
                                        @endif
                                        @if($operable)
                                            <form method="POST" action="{{ route('containers.action', $container) }}">
                                                @csrf
                                                <input type="hidden" name="action" value="inspect">
                                                <button type="submit"><i data-lucide="scan-search"></i> Sync status</button>
                                            </form>
                                            @if($canStop)
                                                <form method="POST" action="{{ route('containers.action', $container) }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="pause">
                                                    <button type="submit"><i data-lucide="pause"></i> Pause</button>
                                                </form>
                                            @endif
                                            @if($container->status === \App\Enums\ContainerStatus::Paused)
                                                <form method="POST" action="{{ route('containers.action', $container) }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="unpause">
                                                    <button type="submit"><i data-lucide="play"></i> Unpause</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('containers.action', $container) }}" onsubmit="return confirm('Remove this container?')">
                                                @csrf
                                                <input type="hidden" name="action" value="remove">
                                                <button type="submit" class="is-danger"><i data-lucide="trash-2"></i> Remove</button>
                                            </form>
                                        @endif
                                    </div>
                                </details>
                            </span>
                        </div>
                    @endforeach
                </div>
                <div class="containers-reference-pagination">
                    <span>Showing {{ $containers->firstItem() }} to {{ $containers->lastItem() }} of {{ $containers->total() }} containers</span>
                    {{ $containers->links() }}
                </div>
            @endif
        </article>

        <div class="containers-help-banner">
            <span class="containers-help-icon"><i data-lucide="life-buoy"></i></span>
            <div>
                <strong>Need help with containers?</strong>
                <p>Learn how to monitor resource usage, restart workloads, and manage ports.</p>
            </div>
            <div class="containers-help-actions">
                <a href="{{ route('support.index') }}" class="button button--secondary">View Documentation <i data-lucide="external-link"></i></a>
                <a href="{{ route('compose.index') }}" class="button button--secondary">Docker Commands</a>
            </div>
        </div>
    </div>
</x-dashboard-layout>
