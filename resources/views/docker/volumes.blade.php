<x-dashboard-layout title="Volumes">
    <div class="volumes-page" x-data="{ deleteOpen: false, deleteVolume: null, deleteConfirmation: '' }">
        <div class="page-heading volumes-reference-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <i data-lucide="chevron-right"></i>
                    Volumes
                </p>
                <h1>Volumes <i data-lucide="info"></i></h1>
                <p>Protect and manage persistent application data on {{ $contextServer?->name ?? 'connected servers' }}.</p>
            </div>
            <div class="heading-actions">
                <a href="{{ route('volumes.index') }}" class="button button--secondary"><i data-lucide="refresh-cw"></i> Refresh</a>
                <a href="{{ route('backups.index') }}" class="button button--primary"><i data-lucide="archive"></i> Manage backups</a>
            </div>
        </div>

        <section class="stats-grid volumes-reference-stats">
            <x-stat-card label="Total Volumes" :value="$counts['all']" :detail="$counts['mounted'].' Mounted'" icon="database" tone="purple" :href="route('volumes.index')" />
            <x-stat-card label="Used Storage" :value="$counts['storage_gb'].' GB'" detail="Across all servers" icon="hard-drive" tone="blue" :href="route('volumes.index', ['sort' => 'size'])" />
            <x-stat-card label="Mounted" :value="$counts['mounted']" detail="Attached to containers" icon="link" tone="green" :href="route('volumes.index', ['mount' => 'mounted'])" />
            <x-stat-card label="Available" :value="$counts['available']" :detail="$counts['backed_up'].' Backed up'" icon="unlink" tone="orange" :href="route('volumes.index', ['mount' => 'available'])" />
        </section>

        <form class="volumes-reference-filters" method="get">
            <label class="table-search">
                <i data-lucide="search"></i>
                <input name="search" value="{{ request('search') }}" placeholder="Search volumes...">
            </label>
            <label class="filter-select">
                <select name="mount" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="mounted" @selected(request('mount') === 'mounted')>Mounted</option>
                    <option value="available" @selected(request('mount') === 'available')>Available</option>
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
                    <option value="size" @selected(request('sort') === 'size')>Largest First</option>
                </select>
                <i data-lucide="arrow-up-down"></i>
            </label>
        </form>

        <article class="card volumes-reference-table-card">
            @if($volumes->isEmpty())
                <div class="empty-state">
                    <span><i data-lucide="database"></i></span>
                    <h2>No volumes found</h2>
                    <p>Deploy an application to create persistent storage volumes.</p>
                    <a href="{{ route('applications.index') }}" class="button button--primary"><i data-lucide="plus"></i> Deploy application</a>
                </div>
            @else
                <div class="volumes-reference-table-scroll">
                    <div class="volumes-reference-table">
                        <div class="volumes-reference-head">
                            <span>Volume</span>
                            <span>Application</span>
                            <span>Server</span>
                            <span>Mount Path</span>
                            <span>Size</span>
                            <span>Status</span>
                            <span>Backup</span>
                            <span>Actions</span>
                        </div>
                        @foreach($volumes as $volume)
                            @php
                                $application = $volume->resolvedApplication();
                                $container = $volume->primaryContainer();
                            @endphp
                            <div class="volumes-reference-row">
                                <span class="volumes-reference-primary">
                                    <span class="volumes-icon"><i data-lucide="database"></i></span>
                                    <span>
                                        <strong>{{ $volume->name }}</strong>
                                        <small class="mono">{{ $volume->docker_name }}</small>
                                        <small>{{ ucfirst($volume->driver) }} driver</small>
                                    </span>
                                </span>
                                <span class="volumes-application">
                                    @if($application || $container?->deployment)
                                        <x-application-icon :application="$application" size="sm" fallback-icon="container" />
                                        <span>
                                            <strong>{{ $volume->applicationName() }}</strong>
                                            <small>{{ $volume->attachedContainersLabel() }}</small>
                                            @if($container?->deployment)
                                                <small><a href="{{ route('deployments.show', $container->deployment) }}">View application</a></small>
                                            @endif
                                        </span>
                                    @else
                                        <strong class="volumes-muted">—</strong>
                                        <small>Not linked to an application</small>
                                    @endif
                                </span>
                                <span>
                                    <strong>{{ $volume->server?->name ?? 'Server removed' }}</strong>
                                    <small>{{ $volume->server?->location ?: ($volume->server ? 'Connected server' : 'No longer available') }}</small>
                                </span>
                                <span>
                                    <strong class="mono">{{ $volume->mountPathLabel() }}</strong>
                                    @if($volume->primaryContainer()?->pivot?->read_only)
                                        <small>Read only</small>
                                    @endif
                                </span>
                                <span class="volumes-metric">
                                    <strong>{{ $volume->sizeLabel() }}</strong>
                                    <div class="metric-track"><i style="width: {{ $volume->usagePercent() }}%"></i></div>
                                </span>
                                <span>
                                    <em class="volumes-status-badge volumes-status-badge--{{ $volume->statusTone() }}"><i></i>{{ $volume->statusLabel() }}</em>
                                </span>
                                <span>
                                    <strong>{{ $volume->backupLabel() }}</strong>
                                    <small>{{ $volume->backed_up_at ? 'Last backup' : 'No backup yet' }}</small>
                                </span>
                                <span class="volumes-actions">
                                    @if($container?->deployment)
                                        <a href="{{ route('deployments.show', $container->deployment) }}" class="icon-button" title="Open application" aria-label="Open application for {{ $volume->name }}"><i data-lucide="external-link"></i></a>
                                    @endif
                                    @if($volume->server && ! $volume->server->trashed())
                                        <a href="{{ route('servers.show', $volume->server) }}" class="icon-button" title="View server" aria-label="View server for {{ $volume->name }}"><i data-lucide="server"></i></a>
                                    @endif
                                    <button
                                        type="button"
                                        class="icon-button is-danger"
                                        title="Delete volume"
                                        aria-label="Delete {{ $volume->name }}"
                                        @click="deleteOpen = true; deleteVolume = @js(['name' => $volume->name, 'action' => route('volumes.destroy', $volume)]); deleteConfirmation = ''"
                                        @if($volume->isMounted()) disabled @endif
                                    ><i data-lucide="trash-2"></i></button>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="volumes-reference-pagination">
                    <span>Showing {{ $volumes->firstItem() }} to {{ $volumes->lastItem() }} of {{ $volumes->total() }} volumes</span>
                    {{ $volumes->links() }}
                </div>
            @endif
        </article>

        <div class="volumes-help-banner">
            <span class="volumes-help-icon"><i data-lucide="shield-alert"></i></span>
            <div>
                <strong>Persistent volumes are never removed automatically</strong>
                <p>Detach every container and type DELETE before a destructive removal is queued.</p>
            </div>
            <a href="{{ route('support.index') }}" class="button button--secondary">View Documentation <i data-lucide="external-link"></i></a>
        </div>

        <div class="modal-backdrop" x-show="deleteOpen" x-cloak @keydown.escape.window="deleteOpen = false">
            <section class="domain-modal" @click.outside="deleteOpen = false">
                <div class="domain-modal-head">
                    <div>
                        <span class="section-icon"><i data-lucide="trash-2"></i></span>
                        <div>
                            <h2>Delete volume</h2>
                            <p>This permanently removes the volume and its stored data.</p>
                        </div>
                    </div>
                    <button type="button" @click="deleteOpen = false"><i data-lucide="x"></i></button>
                </div>
                <form method="POST" :action="deleteVolume?.action">
                    @csrf @method('DELETE')
                    <div class="domain-modal-body">
                        <p class="volumes-delete-copy">Type <strong>DELETE</strong> to confirm removal of <strong x-text="deleteVolume?.name"></strong>.</p>
                        <label class="field">
                            <span>Confirmation</span>
                            <input name="confirmation" x-model="deleteConfirmation" placeholder="DELETE" required>
                        </label>
                    </div>
                    <div class="domain-modal-actions">
                        <button type="button" class="button button--secondary" @click="deleteOpen = false">Cancel</button>
                        <button type="submit" class="button button--primary" :disabled="deleteConfirmation !== 'DELETE'">Delete volume</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-dashboard-layout>
