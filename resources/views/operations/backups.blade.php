<x-dashboard-layout title="Backups">
<div class="backups-page" x-data="{ backupOpen: {{ $errors->any() ? 'true' : 'false' }}, scheduleOpen: false, destinationOpen: false }">
    <div class="page-heading backups-reference-heading">
        <div>
            <p class="breadcrumb">
                <a href="{{ route('dashboard') }}">Operations</a>
                <i data-lucide="chevron-right"></i>
                Backups
            </p>
            <h1>Backups</h1>
            <p>Protect application data with encrypted destinations and tested restore workflows.</p>
        </div>
        <div class="heading-actions">
            <button type="button" class="button button--secondary" @click="destinationOpen = true"><i data-lucide="cloud"></i> Destination</button>
            <button type="button" class="button button--secondary" @click="scheduleOpen = true"><i data-lucide="calendar-clock"></i> Schedule</button>
            <button type="button" class="button button--primary" @click="backupOpen = true"><i data-lucide="plus"></i> Create backup</button>
        </div>
    </div>

    <section class="stats-grid backups-reference-stats">
        <x-stat-card label="Successful" :value="$stats['successful']" detail="Recoverable points" icon="circle-check" tone="green" :href="route('backups.index', ['status' => 'successful'])" />
        <x-stat-card label="In progress" :value="$stats['running']" detail="Queued or running" icon="loader-circle" tone="blue" :href="route('backups.index', ['status' => 'running'])" />
        <x-stat-card label="Stored data" :value="number_format($stats['size'] / 1048576, 1).' MB'" detail="Across destinations" icon="database" tone="purple" :href="route('backups.index')" />
        <x-stat-card label="Schedules" :value="$stats['scheduled']" detail="Enabled policies" icon="calendar-clock" tone="orange" :href="route('backups.index')" />
    </section>

    <div class="backups-reference-layout">
        <form class="backups-reference-filters" method="get">
            <label class="table-search">
                <i data-lucide="search"></i>
                <input name="search" value="{{ request('search') }}" placeholder="Search backups...">
            </label>
            <label class="filter-select">
                <select name="type" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    @foreach(['full' => 'Full', 'application' => 'Application', 'database' => 'Database', 'volume' => 'Volume'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
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
                    <option value="size" @selected(request('sort') === 'size')>Largest First</option>
                </select>
                <i data-lucide="arrow-up-down"></i>
            </label>
        </form>

        <article class="card backups-reference-table-card">
                <div class="backups-reference-toolbar">
                    <div>
                        <h2>Recovery points</h2>
                        <p>Application, database, volume, and full backups.</p>
                    </div>
                    <form method="get" class="backups-reference-filter">
                        @foreach(request()->except('status', 'page') as $key => $value)
                            @if(is_string($value))
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <label class="filter-select">
                            <select name="status" onchange="this.form.submit()">
                                <option value="">All statuses</option>
                                @foreach(['pending' => 'Pending', 'running' => 'Running', 'successful' => 'Successful', 'failed' => 'Failed'] as $value => $label)
                                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <i data-lucide="chevron-down"></i>
                        </label>
                    </form>
                </div>

                @if($backups->isEmpty())
                    <div class="empty-state">
                        <span><i data-lucide="archive"></i></span>
                        <h2>No backups yet</h2>
                        <p>Create a recovery point for a deployed application.</p>
                        <button type="button" class="button button--primary" @click="backupOpen = true"><i data-lucide="plus"></i> Create backup</button>
                    </div>
                @else
                    <div class="backups-reference-table-scroll">
                    <div class="backups-reference-table">
                        <div class="backups-reference-head">
                            <span>Backup</span>
                            <span>Application</span>
                            <span>Type</span>
                            <span>Size</span>
                            <span>Status</span>
                            <span>Created</span>
                            <span class="backups-actions-head">Actions</span>
                        </div>
                        @foreach($backups as $backup)
                            @php($application = $backup->resolvedApplication())
                            <div class="backups-reference-row">
                                <span class="backups-reference-primary">
                                    <span class="backups-icon"><i data-lucide="archive"></i></span>
                                    <span>
                                        <strong>{{ $backup->name }}</strong>
                                        <small>{{ $backup->destinationLabel() }}</small>
                                    </span>
                                </span>
                                <span class="backups-application">
                                    <x-application-icon :application="$application" size="sm" fallback-icon="container" />
                                    <span>
                                        <strong>{{ $backup->applicationName() }}</strong>
                                        <small>{{ $backup->server?->name ?? 'Server removed' }}</small>
                                        @if($backup->deployment)
                                            <small><a href="{{ route('deployments.show', $backup->deployment) }}">View application</a></small>
                                        @endif
                                    </span>
                                </span>
                                <span><strong>{{ $backup->typeLabel() }}</strong></span>
                                <span><strong>{{ $backup->sizeLabel() }}</strong></span>
                                <span>
                                    <em class="backups-status-badge backups-status-badge--{{ $backup->statusTone() }}"><i></i>{{ ucfirst($backup->status) }}</em>
                                </span>
                                <span>
                                    <strong>{{ $backup->created_at->diffForHumans() }}</strong>
                                    @if($backup->expires_at)
                                        <small>Expires {{ $backup->expires_at->diffForHumans() }}</small>
                                    @endif
                                </span>
                                <span class="backups-actions">
                                    @if($backup->canDownload())
                                        <a href="{{ route('backups.download', $backup) }}" class="icon-button" title="Download" aria-label="Download {{ $backup->name }}"><i data-lucide="download"></i></a>
                                    @endif
                                    @if($backup->canRestore())
                                        <form method="POST" action="{{ route('backups.restore', $backup) }}">
                                            @csrf
                                            <button type="submit" class="icon-button" title="Restore" aria-label="Restore {{ $backup->name }}" onclick="return confirm('Restore this backup to {{ $backup->deployment?->name ?? 'its application' }}?')"><i data-lucide="rotate-ccw"></i></button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('backups.destroy', $backup) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="icon-button is-danger" title="Delete" aria-label="Delete {{ $backup->name }}" onclick="return confirm('Delete this backup permanently?')"><i data-lucide="trash-2"></i></button>
                                    </form>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    </div>
                    <div class="backups-reference-pagination">
                        <span>Showing {{ $backups->firstItem() }} to {{ $backups->lastItem() }} of {{ $backups->total() }} backups</span>
                        {{ $backups->links() }}
                    </div>
                @endif
            </article>

        <div class="backups-reference-bottom">
            <article class="card backups-schedules-card">
                <div class="card-head">
                    <div>
                        <h2>Schedules</h2>
                        <p>Automated retention policies.</p>
                    </div>
                    <button type="button" class="button button--secondary button--small" @click="scheduleOpen = true"><i data-lucide="plus"></i></button>
                </div>
                @forelse($schedules as $schedule)
                    <div class="backups-schedule-row">
                        <span>
                            <strong>{{ $schedule->name }}</strong>
                            <small>{{ $schedule->applicationName() }} · {{ $schedule->frequencyLabel() }}</small>
                        </span>
                        <span class="backups-schedule-meta">
                            <em>{{ $schedule->retentionLabel() }}</em>
                            <form method="POST" action="{{ route('backups.schedules.toggle', $schedule) }}">
                                @csrf
                                <button type="submit" class="backups-schedule-toggle {{ $schedule->enabled ? 'is-enabled' : '' }}" title="{{ $schedule->enabled ? 'Pause schedule' : 'Enable schedule' }}">
                                    <i></i>
                                </button>
                            </form>
                        </span>
                    </div>
                @empty
                    <p class="backups-empty-copy">No backup schedules configured.</p>
                @endforelse
            </article>

            <article class="card backups-destinations-card">
                <div class="card-head">
                    <div>
                        <h2>Destinations</h2>
                        <p>Credentials encrypted at rest.</p>
                    </div>
                    <button type="button" class="button button--secondary button--small" @click="destinationOpen = true"><i data-lucide="plus"></i></button>
                </div>
                @forelse($destinations as $destination)
                    <div class="backups-destination-row">
                        <span class="backups-destination-icon"><i data-lucide="{{ $destination->provider === 'local' ? 'hard-drive' : 'cloud' }}"></i></span>
                        <span>
                            <strong>{{ $destination->name }}</strong>
                            <small>{{ $destination->providerLabel() }}{{ $destination->bucket ? ' · '.$destination->bucket : '' }}</small>
                            <small>{{ $destination->usedLabel() }} stored</small>
                        </span>
                        <em class="backups-destination-status {{ $destination->isReady() ? 'is-ready' : '' }}">
                            <i></i>{{ $destination->isReady() ? 'Ready' : 'Pending' }}
                        </em>
                    </div>
                @empty
                    <p class="backups-empty-copy">Local storage is used by default.</p>
                @endforelse
            </article>
        </div>
    </div>

    @include('operations.partials.backup-modals')
</div>
</x-dashboard-layout>
