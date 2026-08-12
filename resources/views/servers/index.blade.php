<x-dashboard-layout title="Servers">
    <div class="servers-page">
        <div class="page-heading servers-reference-heading">
            <div>
                <p class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> <i data-lucide="chevron-right"></i> Servers</p>
                <h1>Servers <i data-lucide="info"></i></h1>
                <p>Manage your servers and deploy applications.</p>
            </div>
            <div class="heading-actions">
                <a href="{{ route('servers.index') }}" class="button button--secondary"><i data-lucide="refresh-cw"></i> Refresh</a>
                <a href="{{ route('servers.create') }}" class="button button--primary"><i data-lucide="plus"></i> Add Server</a>
            </div>
        </div>

        <section class="stats-grid server-reference-stats">
            <x-stat-card label="Total Servers" :value="$counts['all']" :detail="$counts['online'].' Online'" icon="server" tone="purple" :href="route('servers.index')" />
            <x-stat-card label="Total Containers" :value="$counts['containers']" :detail="$counts['containers_running'].' Running'" icon="box" tone="blue" :href="route('containers.index')" />
            <x-stat-card label="Total Volumes" :value="$counts['volumes']" :detail="($counts['volumes_gb'] ?: 0).' GB Used'" icon="database" tone="orange" :href="route('volumes.index')" />
            <x-stat-card label="Total Backups" :value="$counts['backups']" :detail="$counts['last_backup'] ? 'Last: '.$counts['last_backup'] : 'No backups yet'" icon="cloud" tone="purple" :href="route('backups.index')" />
        </section>

        <form class="server-reference-filters" method="get">
            <label class="table-search"><i data-lucide="search"></i><input name="search" value="{{ request('search') }}" placeholder="Search servers..."></label>
            <label class="filter-select"><select name="status" onchange="this.form.submit()"><option value="">All Status</option>@foreach(\App\Enums\ServerStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select><i data-lucide="chevron-down"></i></label>
            <label class="filter-select"><select name="tag" onchange="this.form.submit()"><option value="">All Tags</option>@foreach($tags as $tag)<option value="{{ $tag }}" @selected(request('tag')===$tag)>{{ ucfirst($tag) }}</option>@endforeach</select><i data-lucide="chevron-down"></i></label>
            <label class="filter-select filter-select--sort"><select name="sort" onchange="this.form.submit()"><option value="newest">Newest First</option><option value="oldest" @selected(request('sort')==='oldest')>Oldest First</option><option value="name" @selected(request('sort')==='name')>Name</option></select><i data-lucide="arrow-up-down"></i></label>
        </form>

        <article class="card server-reference-table-card">
            @if($servers->isEmpty())
                <div class="empty-state">
                    <span><i data-lucide="server"></i></span>
                    <h2>No servers found</h2>
                    <p>Connect your first Linux server to start deploying applications.</p>
                    <a href="{{ route('servers.create') }}" class="button button--primary"><i data-lucide="plus"></i> Add Server</a>
                </div>
            @else
                <div class="server-reference-table">
                    <div class="server-reference-head">
                        <span>Server</span><span>IP Address</span><span>Status</span><span>Docker</span><span>Uptime</span><span>Containers</span><span>Apps</span><span>Actions</span>
                    </div>
                    @foreach($servers as $server)
                        @php
                            $containerCap = max(10, $server->containers_count + 3);
                            $containerPct = min(100, round($server->containers_count / max(1, $containerCap) * 100));
                            $serverTags = collect($server->tags ?? [])->filter();
                            if ($loop->first && ! $serverTags->contains(fn ($tag) => str($tag)->lower()->contains('primary'))) {
                                $serverTags = $serverTags->prepend('Primary');
                            }
                            $manageUrl = $server->isProvisioningIncomplete()
                                ? route('servers.provisioning', $server)
                                : route('servers.show', $server);
                            $hasAttachedApps = $server->application_deployments_count > 0;
                            $destroyMessage = 'Remove '.$server->name.' from Uplary Cloud? Remote Docker data will not be deleted.';
                        @endphp
                        <div class="server-reference-row">
                            <a class="server-reference-primary" href="{{ route('servers.details', $server) }}">
                                <span class="server-icon server-icon--reference"><i data-lucide="server"></i></span>
                                <span>
                                    <strong>{{ $server->name }}</strong>
                                    <small>{{ $server->memory_mb ? number_format($server->memory_mb / 1024, 0).' GB RAM' : 'Resources pending' }} / {{ $server->disk_gb ? number_format($server->disk_gb).' GB Disk' : 'Disk pending' }}</small>
                                    @if($serverTags->isNotEmpty())
                                        <span class="server-tag-list">
                                            @foreach($serverTags->take(3) as $tag)
                                                <b>{{ ucfirst($tag) }}</b>
                                            @endforeach
                                        </span>
                                    @endif
                                </span>
                            </a>
                            <span>
                                <strong>{{ $server->ip_address }}</strong>
                                <small>{{ str($server->operating_system)->replace('-', ' ')->title() }}</small>
                            </span>
                            <span>
                                <em class="server-status-badge server-status-badge--{{ $server->status->tone() }}"><i></i>{{ $server->status->label() }}</em>
                            </span>
                            <span>
                                <strong>{{ $server->docker_version ?: 'Pending' }}</strong>
                                <small>{{ $server->docker_version ? 'Running' : 'Awaiting install' }}</small>
                            </span>
                            <span><strong>{{ $server->provisioned_at?->diffForHumans(null, true, parts: 2) ?? '—' }}</strong></span>
                            <span class="server-capacity">
                                <strong>{{ $server->containers_count }} / {{ $containerCap }}</strong>
                                <div class="metric-track"><i style="width:{{ $containerPct }}%"></i></div>
                                <small>{{ $containerPct }}%</small>
                            </span>
                            <span>
                                <strong>{{ $server->application_deployments_count }}</strong>
                                <small class="positive">Running</small>
                            </span>
                            <span class="server-actions">
                                <a href="{{ $manageUrl }}" class="button button--secondary">Manage</a>
                                <details class="server-more">
                                    <summary class="icon-button" aria-label="More actions for {{ $server->name }}"><i data-lucide="ellipsis"></i></summary>
                                    <div class="server-more-menu">
                                        <a href="{{ route('servers.details', $server) }}"><i data-lucide="eye"></i> View server details</a>
                                        <a href="{{ $manageUrl }}"><i data-lucide="settings-2"></i> Manage</a>
                                        @can('delete', $server)
                                            @if($hasAttachedApps)
                                                <span class="server-destroy-blocked" title="Remove attached applications first">
                                                    <i data-lucide="trash-2"></i> Remove applications first
                                                </span>
                                            @else
                                                <form method="POST" action="{{ route('servers.destroy', $server) }}" onsubmit="return confirm(@js($destroyMessage))">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="is-danger"><i data-lucide="trash-2"></i> Destroy server</button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                </details>
                            </span>
                        </div>
                    @endforeach
                </div>
                <div class="server-reference-pagination">
                    <span>Showing {{ $servers->firstItem() }} to {{ $servers->lastItem() }} of {{ $servers->total() }} servers</span>
                    {{ $servers->links() }}
                </div>
            @endif
        </article>

        <div class="server-help-banner">
            <span class="server-icon"><i data-lucide="life-buoy"></i></span>
            <div>
                <strong>Need Help Getting Started?</strong>
                <p>Learn how to connect your server and deploy applications.</p>
            </div>
            <a href="{{ route('support.index') }}" class="button button--secondary">View Documentation <i data-lucide="external-link"></i></a>
        </div>
    </div>
</x-dashboard-layout>
