<x-dashboard-layout title="Your applications">
    <div class="installed-apps-page">
        <div class="page-heading installed-reference-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <i data-lucide="chevron-right"></i>
                    <a href="{{ route('applications.index') }}">Applications</a>
                    <i data-lucide="chevron-right"></i>
                    Your applications
                </p>
                <h1>Your applications</h1>
                <p>Recently deployed workloads across connected servers.</p>
            </div>
            <div class="heading-actions">
                <a href="{{ route('applications.web.create') }}" class="button button--secondary"><i data-lucide="git-branch"></i> Deploy from Git</a>
                <a href="{{ route('applications.custom') }}" class="button button--secondary"><i data-lucide="container"></i> Custom Docker</a>
                <a href="{{ route('applications.index') }}" class="button button--primary"><i data-lucide="plus"></i> Deploy application</a>
            </div>
        </div>

        <section class="stats-grid installed-reference-stats">
            <x-stat-card label="Total Applications" :value="$counts['all']" :detail="$counts['running'].' Running'" icon="blocks" tone="purple" :href="route('applications.installed')" />
            <x-stat-card label="Running" :value="$counts['running']" detail="Healthy workloads" icon="circle-check" tone="green" :href="route('applications.installed', ['status' => 'running'])" />
            <x-stat-card label="In Progress" :value="$counts['active']" detail="Queued or deploying" icon="loader-circle" tone="blue" :href="route('applications.installed', ['status' => 'queued'])" />
            <x-stat-card label="Failed" :value="$counts['failed']" detail="Needs attention" icon="triangle-alert" tone="orange" :href="route('applications.installed', ['status' => 'failed'])" />
        </section>

        <form class="installed-reference-filters" method="get">
            <label class="table-search">
                <i data-lucide="search"></i>
                <input name="search" value="{{ request('search') }}" placeholder="Search applications...">
            </label>
            <label class="filter-select">
                <select name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    @foreach(\App\Enums\DeploymentStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down"></i>
            </label>
            <label class="filter-select">
                <select name="server" onchange="this.form.submit()">
                    <option value="">All Servers</option>
                    @foreach($servers as $server)
                        <option value="{{ $server->id }}" @selected((string) request('server')===(string) $server->id)>{{ $server->name }}</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down"></i>
            </label>
            <label class="filter-select filter-select--sort">
                <select name="sort" onchange="this.form.submit()">
                    <option value="newest">Newest First</option>
                    <option value="oldest" @selected(request('sort')==='oldest')>Oldest First</option>
                    <option value="name" @selected(request('sort')==='name')>Name</option>
                </select>
                <i data-lucide="arrow-up-down"></i>
            </label>
        </form>

        <article class="card installed-reference-table-card">
            @if($deployments->isEmpty())
                <div class="empty-state">
                    <span><i data-lucide="blocks"></i></span>
                    <h2>No applications deployed</h2>
                    <p>Choose a verified template from the marketplace or deploy a custom Docker image.</p>
                    <a href="{{ route('applications.index') }}" class="button button--primary"><i data-lucide="plus"></i> Browse marketplace</a>
                </div>
            @else
                <div class="installed-reference-table">
                    <div class="installed-reference-head">
                        <span>Application</span>
                        <span>Server</span>
                        <span>Image</span>
                        <span>Domain</span>
                        <span>Status</span>
                        <span>Deployed</span>
                        <span></span>
                    </div>
                    @foreach($deployments as $deployment)
                        @php
                            $deleteMessage = 'Remove '.$deployment->name.' from Uplary Cloud? This removes the application from the control plane. Remote containers and volumes on the server will not be deleted.';
                        @endphp
                        <div class="installed-reference-row">
                            <a href="{{ route('deployments.show', $deployment) }}" class="installed-reference-primary">
                                <x-application-icon :application="$deployment->application" size="sm" class="catalog-icon catalog-icon--small" />
                                <span>
                                    <strong>{{ $deployment->name }}</strong>
                                    <small>
                                        @if($deployment->deployment_type === 'git')
                                            {{ $deployment->buildPack?->name ?? 'Git' }} · {{ $deployment->branch }}
                                        @elseif($deployment->application)
                                            {{ $deployment->application->category?->name ?? 'Marketplace' }}
                                        @else
                                            {{ ucfirst($deployment->deployment_type ?? 'custom') }}
                                        @endif
                                    </small>
                                </span>
                            </a>
                            <span>
                                <strong>{{ $deployment->server?->name ?? 'Server removed' }}</strong>
                                <small>{{ $deployment->server?->location ?: ($deployment->server ? 'Connected server' : 'No longer available') }}</small>
                            </span>
                            <span class="mono">
                                <strong>{{ $deployment->docker_image }}:{{ $deployment->docker_tag }}</strong>
                            </span>
                            <span>
                                <strong>{{ $deployment->domain ?: 'IP & port' }}</strong>
                            </span>
                            <span>
                                <em class="installed-status-badge installed-status-badge--{{ $deployment->status->tone() }}"><i></i>{{ $deployment->status->label() }}</em>
                            </span>
                            <span>
                                <strong>{{ $deployment->deployed_at?->diffForHumans() ?? 'Pending' }}</strong>
                            </span>
                            <span class="installed-row-actions">
                                <details class="installed-more">
                                    <summary class="icon-button" aria-label="More actions for {{ $deployment->name }}"><i data-lucide="ellipsis"></i></summary>
                                    <div class="installed-more-menu">
                                        <a href="{{ route('deployments.show', $deployment) }}"><i data-lucide="settings-2"></i> Manage</a>
                                        @if($deployment->server)
                                            @can('operate', $deployment->server)
                                                <form method="POST" action="{{ route('deployments.destroy', $deployment) }}" onsubmit="return confirm(@js($deleteMessage))">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="is-danger"><i data-lucide="trash-2"></i> Delete application</button>
                                                </form>
                                            @endcan
                                        @endif
                                    </div>
                                </details>
                            </span>
                        </div>
                    @endforeach
                </div>
                <div class="installed-reference-pagination">
                    <span>Showing {{ $deployments->firstItem() }} to {{ $deployments->lastItem() }} of {{ $deployments->total() }} applications</span>
                    {{ $deployments->links() }}
                </div>
            @endif
        </article>

        <div class="installed-help-banner">
            <span class="installed-help-icon"><i data-lucide="life-buoy"></i></span>
            <div>
                <strong>Need Help With Deployments?</strong>
                <p>Learn how to configure domains, environment variables, and rollbacks.</p>
            </div>
            <a href="{{ route('support.index') }}" class="button button--secondary">View Documentation <i data-lucide="external-link"></i></a>
        </div>
    </div>
</x-dashboard-layout>
