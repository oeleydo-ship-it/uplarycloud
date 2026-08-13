<x-dashboard-layout title="API Tokens">
<div class="api-reference-page" x-data="{createOpen:{{ $errors->any()?'true':'false' }},editOpen:false}">
    <div class="page-heading api-heading">
        <div>
            <p class="breadcrumb">Dashboard / API Tokens</p>
            <h1>API Tokens</h1>
            <p>Create and manage API tokens to access the Uplary API securely.</p>
        </div>
        <div class="heading-actions">
            <x-plan-locked-action quota="api_tokens" label="API tokens">
                <button class="button button--primary" type="button" @click="createOpen=true"><i data-lucide="plus"></i> Create New Token</button>
            </x-plan-locked-action>
        </div>
    </div>
    <x-plan-upgrade-banner quota="api_tokens" />

    @if($plainToken)
        <section class="token-reveal">
            <div>
                <i data-lucide="key-round"></i>
                <span>
                    <strong>Copy your token now</strong>
                    <small>For security, it will never be displayed again.</small>
                </span>
            </div>
            <code id="plain-token">{{ $plainToken }}</code>
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('plain-token').textContent)"><i data-lucide="copy"></i> Copy</button>
        </section>
    @endif

    <section class="stats-grid api-stats">
        <x-stat-card label="Total Tokens" :value="$stats['total']" detail="Across all environments" icon="key-round" tone="purple"/>
        <x-stat-card label="Active Tokens" :value="$stats['active']" :detail="$stats['total'] ? round($stats['active']/$stats['total']*100).'% of total tokens' : 'No active tokens'" icon="shield-check" tone="green"/>
        <x-stat-card label="Expired Tokens" :value="$stats['expired']" detail="Rotation required" icon="clock-3" tone="orange"/>
        <x-stat-card label="Revoked Tokens" :value="$stats['revoked']" detail="Access removed" icon="ban" tone="blue"/>
    </section>

    <form class="api-filterbar" method="get">
        <label class="table-search">
            <i data-lucide="search"></i>
            <input name="search" value="{{ request('search') }}" placeholder="Search tokens by name or prefix...">
        </label>
        <select name="status">
            <option value="">All Status</option>
            @foreach(['active','expired','revoked'] as $status)
                <option value="{{ $status }}" @selected(request('status')===$status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <select name="permission">
            <option value="">All Permissions</option>
            @foreach($scopes as $scope)
                <option value="{{ $scope }}" @selected(request('permission')===$scope)>{{ str($scope)->replace(':',': ') }}</option>
            @endforeach
        </select>
        <select name="environment">
            <option value="">All Environments</option>
            @foreach(['production','staging','development'] as $environment)
                <option value="{{ $environment }}" @selected(request('environment')===$environment)>{{ ucfirst($environment) }}</option>
            @endforeach
        </select>
        <button class="button button--secondary"><i data-lucide="list-filter"></i> Filters</button>
    </form>

    <section class="api-content-grid">
        <div>
            <article class="card api-token-table-card">
                <div class="api-token-head">
                    <span>Token name</span>
                    <span>Token prefix</span>
                    <span>Permissions</span>
                    <span>Environment</span>
                    <span>Last used</span>
                    <span>Status</span>
                    <span>Actions</span>
                </div>
                @forelse($tokens as $token)
                    <a class="api-token-row {{ $selected?->is($token)?'is-selected':'' }}" href="{{ route('api-tokens.index', array_merge(request()->except('page'), ['selected' => $token->id])) }}">
                        <span>
                            <strong>{{ $token->name }}</strong>
                            <small>{{ match(true) {
                                str_contains(strtolower($token->name),'deploy') => 'Used for automated deployments',
                                str_contains(strtolower($token->name),'monitor') => 'Prometheus & Grafana access',
                                str_contains(strtolower($token->name),'backup') => 'Automated backup scripts',
                                default => 'Secure API integration',
                            } }}</small>
                        </span>
                        <code>{{ $token->display_prefix }}</code>
                        <span class="scope-list">
                            @foreach(array_slice($token->abilities ?? [], 0, 2) as $scope)
                                <em>{{ str($scope)->replace(':',': ') }}</em>
                            @endforeach
                            @if(count($token->abilities ?? []) > 2)
                                <small>+{{ count($token->abilities) - 2 }}</small>
                            @endif
                        </span>
                        <span><em class="environment-badge environment-badge--{{ $token->environment }}">{{ ucfirst($token->environment) }}</em></span>
                        <time>
                            {{ $token->last_used_at?->format('M j, Y') ?? 'Never' }}
                            <small>{{ $token->last_used_at?->format('g:i A') }}</small>
                        </time>
                        <span><em class="api-status api-status--{{ $token->status }}">{{ ucfirst($token->status) }}</em></span>
                        <span class="api-more"><i data-lucide="ellipsis"></i></span>
                    </a>
                @empty
                    <div class="healthy-empty">
                        <i data-lucide="key-round"></i>
                        <strong>No API tokens</strong>
                        <small>Create a scoped token for CI/CD or automation.</small>
                    </div>
                @endforelse
                @if($tokens->hasPages())
                    <div class="api-pagination">
                        <span>Showing {{ $tokens->firstItem() }} to {{ $tokens->lastItem() }} of {{ $tokens->total() }} tokens</span>
                        {{ $tokens->links() }}
                    </div>
                @endif
            </article>

            <article class="card api-about">
                <div>
                    <h2>About API Tokens</h2>
                    <p>API tokens allow you to authenticate with the Uplary API. Tokens are scoped to specific permissions and can be restricted to IP addresses.</p>
                    <a href="{{ route('system-health') }}">View API Documentation <i data-lucide="external-link"></i></a>
                </div>
                <div>
                    <h2>Best Practices</h2>
                    <p><i data-lucide="circle-check"></i> Use tokens with minimum required permissions</p>
                    <p><i data-lucide="circle-check"></i> Rotate tokens regularly</p>
                    <p><i data-lucide="circle-check"></i> Never share tokens or commit them to code repositories</p>
                    <p><i data-lucide="circle-check"></i> Monitor token usage and revoke unused tokens</p>
                </div>
            </article>
        </div>

        <aside class="card api-token-details">
            @if($selected)
                <h2>Token Details</h2>
                <div class="api-detail-title">
                    <strong>{{ $selected->name }}</strong>
                    <em class="api-status api-status--{{ $selected->status }}">{{ ucfirst($selected->status) }}</em>
                    <small>Created on {{ $selected->created_at->format('M j, Y g:i A') }}</small>
                </div>
                <div class="api-detail-block">
                    <label>Token Prefix</label>
                    <code>{{ $selected->display_prefix }} <button type="button" onclick="navigator.clipboard.writeText('{{ $selected->display_prefix }}')"><i data-lucide="copy"></i></button></code>
                </div>
                <div class="api-detail-block">
                    <label>Permissions</label>
                    <div class="scope-list">
                        @foreach($selected->abilities ?? [] as $scope)
                            <em>{{ str($scope)->replace(':',': ') }}</em>
                        @endforeach
                    </div>
                </div>
                <div class="api-detail-block">
                    <label>Environment</label>
                    <em class="environment-badge environment-badge--{{ $selected->environment }}">{{ ucfirst($selected->environment) }}</em>
                </div>
                <dl>
                    <div>
                        <dt>Last Used</dt>
                        <dd>{{ $selected->last_used_at?->format('M j, Y g:i A') ?? 'Never' }}</dd>
                    </div>
                    <div>
                        <dt>IP Address</dt>
                        <dd>{{ collect($selected->ip_restrictions)->first() ?? 'Any allowed' }}</dd>
                    </div>
                    <div>
                        <dt>Created By</dt>
                        <dd>{{ auth()->user()->name }}<small>{{ auth()->user()->email }}</small></dd>
                    </div>
                    <div>
                        <dt>Expires</dt>
                        <dd>{{ $selected->expires_at?->format('M j, Y') ?? 'Never' }}</dd>
                    </div>
                </dl>
                @if($selected->status !== 'revoked')
                    <button class="button button--secondary button--full" type="button" @click="editOpen=true"><i data-lucide="pencil"></i> Edit Token</button>
                    <form method="post" action="{{ route('api-tokens.destroy', $selected) }}">
                        @csrf @method('DELETE')
                        <button class="button button--danger button--full" onclick="return confirm('Revoke this token?')"><i data-lucide="trash-2"></i> Revoke Token</button>
                    </form>
                @endif
            @else
                <div class="healthy-empty">
                    <i data-lucide="key-round"></i>
                    <strong>Select a token</strong>
                    <small>Choose a token to view details.</small>
                </div>
            @endif
        </aside>
    </section>

    <div class="modal-backdrop" x-show="createOpen" x-cloak @click.self="createOpen=false">
        <section class="domain-modal api-token-modal">
            <div class="domain-modal-head">
                <div>
                    <span class="section-icon"><i data-lucide="key-round"></i></span>
                    <div>
                        <h2>Create API Token</h2>
                        <p>Grant only the permissions this integration needs.</p>
                    </div>
                </div>
                <button type="button" @click="createOpen=false"><i data-lucide="x"></i></button>
            </div>
            @include('commercial.partials.api-token-form', ['action' => route('api-tokens.store'), 'token' => null])
        </section>
    </div>

    @if($selected)
        <div class="modal-backdrop" x-show="editOpen" x-cloak @click.self="editOpen=false">
            <section class="domain-modal api-token-modal">
                <div class="domain-modal-head">
                    <div>
                        <span class="section-icon"><i data-lucide="pencil"></i></span>
                        <div>
                            <h2>Edit API Token</h2>
                            <p>Update access without exposing the token secret.</p>
                        </div>
                    </div>
                    <button type="button" @click="editOpen=false"><i data-lucide="x"></i></button>
                </div>
                @include('commercial.partials.api-token-form', ['action' => route('api-tokens.update', $selected), 'token' => $selected])
            </section>
        </div>
    @endif
</div>
</x-dashboard-layout>
