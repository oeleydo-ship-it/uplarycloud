<x-dashboard-layout title="Domains">
<div class="domains-page" x-data="{ addOpen: {{ $errors->any() && ! session('import_errors') ? 'true' : 'false' }}, importOpen: false }">
    <div class="page-heading domains-reference-heading">
        <div>
            <p class="breadcrumb">
                <a href="{{ route('dashboard') }}">Dashboard</a>
                <i data-lucide="chevron-right"></i>
                Domains
            </p>
            <h1>Domains <i data-lucide="info"></i></h1>
            <p>Manage your domains, DNS, SSL certificates and redirects.</p>
        </div>
        <div class="heading-actions">
            <x-plan-locked-action quota="domains" label="domains">
                <button type="button" class="button button--secondary" @click="importOpen = true"><i data-lucide="download"></i> Import Domain</button>
            </x-plan-locked-action>
            <x-plan-locked-action quota="domains" label="domains">
                <button type="button" class="button button--primary" @click="addOpen = true"><i data-lucide="plus"></i> Add Domain</button>
            </x-plan-locked-action>
        </div>
        <x-plan-upgrade-banner quota="domains" />
    </div>

    <section class="stats-grid domains-reference-stats">
        <x-stat-card label="Total Domains" :value="$stats['total']" :detail="$stats['active'].' Active'" icon="globe-2" tone="purple" :href="route('domains.index')" />
        <x-stat-card label="SSL Certificates" :value="$stats['ssl']" :detail="$stats['ssl'].' Valid'" icon="shield-check" tone="green" :href="route('domains.index', ['ssl' => 'valid'])" />
        <x-stat-card label="Expiring Soon" :value="$stats['expiring']" detail="In 30 days" icon="refresh-cw" tone="orange" :href="route('domains.index', ['sort' => 'expiring'])" />
        <x-stat-card label="Total Redirects" :value="$stats['redirects']" detail="Active" icon="package" tone="blue" :href="route('domains.index', ['type' => 'redirect'])" />
    </section>

    <form class="domains-reference-filters" method="get">
        <label class="table-search">
            <i data-lucide="search"></i>
            <input name="search" value="{{ request('search') }}" placeholder="Search domains...">
        </label>
        <label class="filter-select">
            <select name="status" onchange="this.form.submit()">
                <option value="">All Status</option>
                @foreach(['active' => 'Active', 'pending' => 'Pending', 'verifying' => 'Verifying', 'failed' => 'Failed'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <i data-lucide="chevron-down"></i>
        </label>
        <label class="filter-select">
            <select name="ssl" onchange="this.form.submit()">
                <option value="">All SSL Status</option>
                @foreach(['valid' => 'Valid', 'pending' => 'Pending', 'expiring' => 'Expiring', 'expired' => 'Expired', 'failed' => 'Failed', 'disabled' => 'No SSL'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('ssl') === $value)>{{ $label }}</option>
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
                <option value="expiring" @selected(request('sort') === 'expiring')>Expiring First</option>
            </select>
            <i data-lucide="arrow-up-down"></i>
        </label>
    </form>

    <article class="card domains-reference-table-card">
        @if($domains->isEmpty())
            <div class="empty-state">
                <span><i data-lucide="globe-lock"></i></span>
                <h2>No domains configured</h2>
                <p>Add a domain to route an application with automatic SSL.</p>
                <button type="button" class="button button--primary" @click="addOpen = true"><i data-lucide="plus"></i> Add your first domain</button>
            </div>
        @else
            <div class="domains-reference-table">
                <div class="domains-reference-head">
                    <span>Domain</span>
                    <span>Server</span>
                    <span>SSL Status</span>
                    <span>Application</span>
                    <span>Type</span>
                    <span>Created</span>
                    <span>Expires</span>
                    <span>Actions</span>
                </div>
                @foreach($domains as $domain)
                    @php
                        $application = $domain->resolvedApplication();
                        $expiryDays = $domain->daysUntilExpiry();
                    @endphp
                    <div class="domains-reference-row">
                        <span class="domains-reference-primary">
                            <x-application-icon :application="$application" size="sm" fallback-icon="globe" />
                            <span>
                                <span class="domain-hostname">
                                    <a href="{{ route('domains.show', $domain) }}">{{ $domain->hostname }}</a>
                                    @if($domain->status === 'active')
                                        <a href="https://{{ $domain->hostname }}" target="_blank" rel="noopener" class="domain-external" aria-label="Open {{ $domain->hostname }}"><i data-lucide="external-link"></i></a>
                                    @endif
                                    @if($domain->isPrimary())
                                        <b class="domain-badge domain-badge--primary">Primary</b>
                                    @elseif($domain->isRootDomain())
                                        <b class="domain-badge domain-badge--root">Root Domain</b>
                                    @endif
                                </span>
                                <small>Added {{ $domain->created_at->diffForHumans(null, true) }} ago</small>
                            </span>
                        </span>
                        <span>
                            <strong>{{ $domain->server?->name ?? 'Server removed' }}</strong>
                            @if($domain->server)
                                <small class="domain-server-status domain-server-status--{{ $domain->server->status->tone() }}">{{ $domain->server->status->label() }}</small>
                            @else
                                <small class="domain-server-status domain-server-status--muted">Unavailable</small>
                            @endif
                        </span>
                        <span class="domain-ssl">
                            <em class="domain-ssl-state domain-ssl-state--{{ $domain->sslTone() }}">
                                <i data-lucide="{{ $domain->hasValidSsl() ? 'lock' : 'lock-open' }}"></i>
                                {{ $domain->ssl_status === 'disabled' ? 'No SSL' : $domain->sslStatusLabel() }}
                            </em>
                            @if($domain->hasValidSsl())
                                <small>{{ $domain->certificate_provider }}</small>
                            @elseif($domain->isDnsVerified())
                                <form method="POST" action="{{ route('domains.certificate', $domain) }}">
                                    @csrf
                                    <button type="submit" class="domain-inline-action">Add certificate</button>
                                </form>
                            @else
                                <small>DNS {{ strtolower($domain->dnsStatusLabel()) }}</small>
                            @endif
                        </span>
                        <span class="domain-application">
                            @if($domain->isRedirect())
                                <strong class="domain-muted">—</strong>
                                <small>To {{ $domain->redirect_to }}</small>
                            @else
                                <x-application-icon :application="$application" size="sm" fallback-icon="container" />
                                <span>
                                    <strong>{{ $domain->applicationName() }}</strong>
                                    <small>{{ $domain->applicationDetail() ?? ($domain->deployment?->container_port ? 'Port '.$domain->deployment->container_port : 'Application removed') }}</small>
                                </span>
                            @endif
                        </span>
                        <span>
                            <em class="domain-type domain-type--{{ $domain->isRedirect() ? 'redirect' : 'application' }}">{{ $domain->typeLabel() }}</em>
                        </span>
                        <span>
                            <strong>{{ $domain->created_at->format('M d, Y') }}</strong>
                            <small>{{ $domain->created_at->diffForHumans() }}</small>
                        </span>
                        <span>
                            @if($domain->certificate_expires_at && $domain->hasValidSsl())
                                <strong>{{ $domain->certificate_expires_at->format('M d, Y') }}</strong>
                                <small class="{{ $domain->isExpiringSoon() ? 'domain-expiry-soon' : 'domain-expiry-ok' }}">({{ $expiryDays }} days)</small>
                            @else
                                <strong class="domain-muted">—</strong>
                            @endif
                        </span>
                        <span class="domains-actions">
                            <a href="{{ route('domains.show', $domain) }}" class="button button--secondary">Manage</a>
                            <details class="domains-more">
                                <summary class="icon-button" aria-label="More actions for {{ $domain->hostname }}"><i data-lucide="ellipsis"></i></summary>
                                <div class="domains-more-menu">
                                    <form method="POST" action="{{ route('domains.verify', $domain) }}">
                                        @csrf
                                        <button type="submit"><i data-lucide="refresh-cw"></i> Verify DNS</button>
                                    </form>
                                    @if($domain->isDnsVerified())
                                        <form method="POST" action="{{ route('domains.configure', $domain) }}">
                                            @csrf
                                            <button type="submit"><i data-lucide="route"></i> Configure proxy</button>
                                        </form>
                                        <form method="POST" action="{{ route('domains.certificate', $domain) }}">
                                            @csrf
                                            <button type="submit"><i data-lucide="shield-check"></i> {{ $domain->hasValidSsl() ? 'Renew certificate' : 'Issue certificate' }}</button>
                                        </form>
                                    @endif
                                    @if($domain->deployment)
                                        <a href="{{ route('deployments.show', $domain->deployment) }}"><i data-lucide="blocks"></i> Open application</a>
                                    @endif
                                    <a href="{{ route('servers.show', $domain->server) }}"><i data-lucide="server"></i> View server</a>
                                    <form method="POST" action="{{ route('domains.destroy', $domain) }}" onsubmit="return confirm('Remove this domain route?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="is-danger"><i data-lucide="trash-2"></i> Remove domain</button>
                                    </form>
                                </div>
                            </details>
                        </span>
                    </div>
                @endforeach
            </div>
            <div class="domains-reference-pagination">
                <span>Showing {{ $domains->firstItem() }} to {{ $domains->lastItem() }} of {{ $domains->total() }} domains</span>
                {{ $domains->links() }}
            </div>
        @endif
    </article>

    <div class="modal-backdrop" x-show="addOpen" x-cloak @keydown.escape.window="addOpen = false">
        <section class="domain-modal domain-modal--form" @click.outside="addOpen = false">
            <div class="domain-modal-head">
                <div>
                    <span class="section-icon"><i data-lucide="globe-2"></i></span>
                    <div>
                        <h2>Add a domain</h2>
                        <p>Connect a hostname to a running application.</p>
                    </div>
                </div>
                <button type="button" class="domain-modal-close" @click="addOpen = false" aria-label="Close"><i data-lucide="x"></i></button>
            </div>
            <form method="POST" action="{{ route('domains.store') }}">
                @csrf
                <div class="domain-modal-body">
                    <label class="field">
                        <span>Application <b>*</b></span>
                        <select name="application_deployment_id" required>
                            <option value="">Select an application</option>
                            @foreach($deployments as $deployment)
                                <option value="{{ $deployment->id }}" @selected(old('application_deployment_id') == $deployment->id)>{{ $deployment->name }} · {{ $deployment->server?->name ?? 'Server removed' }}</option>
                            @endforeach
                        </select>
                        @error('application_deployment_id')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="field">
                        <span>Domain name <b>*</b></span>
                        <div class="domain-input-group">
                            <span class="domain-input-affix" aria-hidden="true"><i data-lucide="globe"></i></span>
                            <input name="hostname" value="{{ old('hostname') }}" required placeholder="app.example.com" autocomplete="off">
                        </div>
                        @error('hostname')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="field">
                        <span>Redirect to <em>Optional</em></span>
                        <div class="domain-input-group">
                            <span class="domain-input-affix" aria-hidden="true"><i data-lucide="corner-down-right"></i></span>
                            <input name="redirect_to" value="{{ old('redirect_to') }}" placeholder="www.example.com" autocomplete="off">
                        </div>
                        <small>Send all traffic from this hostname to another domain.</small>
                    </label>
                    <fieldset class="domain-toggle-list">
                        <legend class="domain-toggle-heading">Certificate options</legend>
                        <label class="domain-toggle">
                            <input class="domain-toggle-input" type="checkbox" name="ssl_enabled" value="1" checked>
                            <span class="domain-toggle-icon" aria-hidden="true"><i data-lucide="lock-keyhole"></i></span>
                            <span class="domain-toggle-copy">
                                <span class="domain-toggle-title">Managed SSL</span>
                                <span class="domain-toggle-hint">Issue with Let's Encrypt</span>
                            </span>
                            <span class="domain-toggle-switch" aria-hidden="true"><span class="domain-toggle-knob"></span></span>
                        </label>
                        <label class="domain-toggle">
                            <input class="domain-toggle-input" type="checkbox" name="force_https" value="1" checked>
                            <span class="domain-toggle-icon" aria-hidden="true"><i data-lucide="shield-check"></i></span>
                            <span class="domain-toggle-copy">
                                <span class="domain-toggle-title">Force HTTPS</span>
                                <span class="domain-toggle-hint">Redirect port 80 to 443</span>
                            </span>
                            <span class="domain-toggle-switch" aria-hidden="true"><span class="domain-toggle-knob"></span></span>
                        </label>
                        <label class="domain-toggle">
                            <input class="domain-toggle-input" type="checkbox" name="auto_renew" value="1" checked>
                            <span class="domain-toggle-icon" aria-hidden="true"><i data-lucide="refresh-cw"></i></span>
                            <span class="domain-toggle-copy">
                                <span class="domain-toggle-title">Auto renew</span>
                                <span class="domain-toggle-hint">Renew before expiration</span>
                            </span>
                            <span class="domain-toggle-switch" aria-hidden="true"><span class="domain-toggle-knob"></span></span>
                        </label>
                    </fieldset>
                    <aside class="dns-preview">
                        <i data-lucide="info"></i>
                        <p>After adding, create an <strong>A record</strong> to the selected server. Verification and SSL continue automatically.</p>
                    </aside>
                </div>
                <div class="domain-modal-actions">
                    <button type="button" class="button button--secondary" @click="addOpen = false">Cancel</button>
                    <button type="submit" class="button button--primary">Add domain</button>
                </div>
            </form>
        </section>
    </div>

    <div class="modal-backdrop" x-show="importOpen" x-cloak @keydown.escape.window="importOpen = false">
        <section class="domain-modal domain-modal--form" @click.outside="importOpen = false">
            <div class="domain-modal-head">
                <div>
                    <span class="section-icon"><i data-lucide="download"></i></span>
                    <div>
                        <h2>Import domains</h2>
                        <p>Attach several hostnames to one application at once.</p>
                    </div>
                </div>
                <button type="button" class="domain-modal-close" @click="importOpen = false" aria-label="Close"><i data-lucide="x"></i></button>
            </div>
            <form method="POST" action="{{ route('domains.import') }}">
                @csrf
                <div class="domain-modal-body">
                    <label class="field">
                        <span>Application <b>*</b></span>
                        <select name="application_deployment_id" required>
                            <option value="">Select an application</option>
                            @foreach($deployments as $deployment)
                                <option value="{{ $deployment->id }}">{{ $deployment->name }} · {{ $deployment->server?->name ?? 'Server removed' }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field">
                        <span>Domains <b>*</b></span>
                        <textarea name="hostnames" rows="5" required placeholder="app.example.com&#10;www.example.com&#10;docs.example.com"></textarea>
                        <small>One hostname per line. Commas and spaces also work. Duplicates and invalid entries are skipped.</small>
                    </label>
                    <fieldset class="domain-toggle-list">
                        <legend class="domain-toggle-heading">Certificate options</legend>
                        <label class="domain-toggle">
                            <input class="domain-toggle-input" type="checkbox" name="ssl_enabled" value="1" checked>
                            <span class="domain-toggle-icon" aria-hidden="true"><i data-lucide="lock-keyhole"></i></span>
                            <span class="domain-toggle-copy">
                                <span class="domain-toggle-title">Managed SSL</span>
                                <span class="domain-toggle-hint">Issue with Let's Encrypt</span>
                            </span>
                            <span class="domain-toggle-switch" aria-hidden="true"><span class="domain-toggle-knob"></span></span>
                        </label>
                        <label class="domain-toggle">
                            <input class="domain-toggle-input" type="checkbox" name="force_https" value="1" checked>
                            <span class="domain-toggle-icon" aria-hidden="true"><i data-lucide="shield-check"></i></span>
                            <span class="domain-toggle-copy">
                                <span class="domain-toggle-title">Force HTTPS</span>
                                <span class="domain-toggle-hint">Redirect port 80 to 443</span>
                            </span>
                            <span class="domain-toggle-switch" aria-hidden="true"><span class="domain-toggle-knob"></span></span>
                        </label>
                    </fieldset>
                    <aside class="dns-preview">
                        <i data-lucide="info"></i>
                        <p>Each imported domain needs an <strong>A record</strong> pointing to the application server before SSL can be issued.</p>
                    </aside>
                </div>
                <div class="domain-modal-actions">
                    <button type="button" class="button button--secondary" @click="importOpen = false">Cancel</button>
                    <button type="submit" class="button button--primary">Import domains</button>
                </div>
            </form>
        </section>
    </div>
</div>
</x-dashboard-layout>
