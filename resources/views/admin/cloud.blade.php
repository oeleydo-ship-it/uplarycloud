<x-admin-layout title="Cloud Infrastructure">
<div class="admin-heading">
    <div>
        <p>SUPERADMIN / CLOUD INFRASTRUCTURE</p>
        <h1>Managed Cloud</h1>
        <span>Control provider APIs, availability, server catalog, and customer markup.</span>
    </div>
    <div class="admin-heading-actions">
        <form method="post" action="{{ route('admin.cloud.plans.sync') }}">@csrf<button class="button button--secondary" @disabled($connections->whereNotNull('last_verified_at')->isEmpty())><i data-lucide="refresh-cw"></i>Sync plans</button></form>
        <button class="button button--secondary" onclick="document.getElementById('new-cloud').showModal()"><i data-lucide="plug-zap"></i>Connect API</button>
        <button class="button button--primary" onclick="document.getElementById('new-cloud-plan').showModal()"><i data-lucide="plus"></i>Add server plan</button>
    </div>
</div>

<section class="admin-cloud-stats">
    <article class="card"><span><i data-lucide="cloud"></i></span><div><small>Cloud APIs</small><strong>{{ $connections->count() }}</strong><em>{{ $connections->where('active',true)->count() }} enabled</em></div></article>
    <article class="card"><span><i data-lucide="badge-check"></i></span><div><small>Verified</small><strong>{{ $connections->whereNotNull('last_verified_at')->count() }}</strong><em>DigitalOcean & Hetzner</em></div></article>
    <article class="card"><span><i data-lucide="server-cog"></i></span><div><small>Customer plans</small><strong>{{ $plans->count() }}</strong><em>{{ $plans->where('active',true)->count() }} published</em></div></article>
</section>

<div class="admin-cloud-grid">
    <section class="card">
        <div class="admin-card-head"><div><h2>Platform cloud connections</h2><p>These accounts provision servers for every tenant.</p></div></div>
        <div class="admin-cloud-list">
            @forelse($connections as $connection)
                <article>
                    <span class="cloud-provider-logo">{{ $connection->provider === 'digitalocean' ? 'DO' : 'HZ' }}</span>
                    <div>
                        <strong>{{ $connection->name }}</strong>
                        <small>{{ ucfirst($connection->provider) }} · {{ $connection->last_verified_at ? 'Verified '.$connection->last_verified_at->diffForHumans() : 'Not verified' }}</small>
                        @if($connection->last_error)<em>{{ str($connection->last_error)->limit(70) }}</em>@endif
                    </div>
                    <span class="admin-pill {{ $connection->active ? '' : 'admin-pill--off' }}">{{ $connection->active ? 'Enabled' : 'Disabled' }}</span>
                    <form method="post" action="{{ route('admin.cloud.connections.verify', $connection) }}">@csrf<button class="admin-edit">Verify</button></form>
                    @if($connection->last_verified_at)
                        <form method="post" action="{{ route('admin.cloud.connections.sync', $connection) }}">@csrf<button class="admin-edit">Sync</button></form>
                    @endif
                    <button class="admin-edit" onclick="document.getElementById('cloud-{{ $connection->id }}').showModal()">Edit</button>
                </article>
            @empty
                <div class="admin-cloud-empty"><i data-lucide="cloud-off"></i><strong>No platform provider connected</strong><span>Add DigitalOcean or Hetzner to publish managed servers.</span></div>
            @endforelse
        </div>
    </section>

    <section class="card">
        <div class="admin-card-head">
            <div>
                <h2>Customer pricing catalog</h2>
                <p>Synced from verified APIs. Customer price = provider cost × (1 + markup%).</p>
            </div>
            <form class="global-markup-form" method="post" action="{{ route('admin.cloud.markup.update') }}">
                @csrf @method('put')
                <label>
                    <span>Global markup</span>
                    <input type="number" name="markup_percentage" min="0" max="1000" value="{{ old('markup_percentage', $globalMarkup) }}">
                    <b>%</b>
                </label>
                <button class="button button--primary">Apply to all enabled plans</button>
            </form>
        </div>

        <div class="admin-table-card">
            <table>
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Resources</th>
                        <th>Cost</th>
                        <th>Markup</th>
                        <th>Customer price</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($plans as $plan)
                        <tr>
                            <td>
                                <strong>{{ $plan->name }}</strong>
                                <small>{{ ucfirst($plan->provider) }} · {{ $plan->provider_plan_id }}</small>
                            </td>
                            <td>{{ $plan->cpu_cores }} vCPU · {{ round($plan->memory_mb / 1024, 1) }} GB · {{ $plan->disk_gb }} GB</td>
                            <td>{{ strtoupper($plan->currency) === 'EUR' ? '€' : '$' }}{{ number_format($plan->monthly_cost / 100, 2) }}</td>
                            <td>{{ $plan->markup_percentage }}%</td>
                            <td><strong>{{ $plan->priceLabel() }}</strong></td>
                            <td><span class="admin-pill {{ $plan->active ? '' : 'admin-pill--off' }}">{{ $plan->active ? 'Published' : 'Hidden' }}</span></td>
                            <td><button class="admin-edit" onclick="document.getElementById('cloud-plan-{{ $plan->id }}').showModal()">Edit</button></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="admin-cloud-empty">
                                    <i data-lucide="server-cog"></i>
                                    <strong>No plans published yet</strong>
                                    <span>Connect and verify a provider API to sync available sizes, or add a plan manually.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<dialog id="new-cloud" class="admin-dialog">
    <form method="post" action="{{ route('admin.cloud.connections.store') }}">
        @csrf
        <h2>Connect cloud API</h2>
        <p>Credentials are encrypted, verified, and used to sync the customer pricing catalog.</p>
        <label><span>Name</span><input name="name" required placeholder="Production DigitalOcean"></label>
        <label><span>Provider</span><select name="provider"><option value="digitalocean">DigitalOcean</option><option value="hetzner">Hetzner Cloud</option></select></label>
        <label><span>API token</span><input type="password" name="api_token" required autocomplete="new-password"></label>
        <label><span>Account / project ID</span><input name="account_id"></label>
        <label class="admin-check"><input type="checkbox" name="active" value="1" checked> Enable for customers after verification</label>
        <div>
            <button type="button" class="button button--secondary" onclick="this.closest('dialog').close()">Cancel</button>
            <button class="button button--primary">Save, verify & sync</button>
        </div>
    </form>
</dialog>

<dialog id="new-cloud-plan" class="admin-dialog admin-dialog--wide">
    <form method="post" action="{{ route('admin.cloud.plans.store') }}">
        @csrf
        @include('admin.partials.cloud-plan-fields', ['plan' => null])
        <div>
            <button type="button" class="button button--secondary" onclick="this.closest('dialog').close()">Cancel</button>
            <button class="button button--primary">Publish plan</button>
        </div>
    </form>
</dialog>

@foreach($connections as $connection)
    <dialog id="cloud-{{ $connection->id }}" class="admin-dialog">
        <form method="post" action="{{ route('admin.cloud.connections.update', $connection) }}">
            @csrf @method('put')
            <h2>Edit {{ $connection->name }}</h2>
            <label><span>Name</span><input name="name" value="{{ $connection->name }}" required></label>
            <label><span>Replace API token</span><input type="password" name="api_token" placeholder="Leave blank to keep current token"></label>
            <label><span>Account / project ID</span><input name="account_id" value="{{ $connection->account_id }}"></label>
            <label class="admin-check"><input type="checkbox" name="active" value="1" @checked($connection->active)> Enabled for customer provisioning</label>
            <div>
                <button type="button" class="button button--secondary" onclick="this.closest('dialog').close()">Cancel</button>
                <button class="button button--primary">Save connection</button>
            </div>
        </form>
    </dialog>
@endforeach

@foreach($plans as $plan)
    <dialog id="cloud-plan-{{ $plan->id }}" class="admin-dialog admin-dialog--wide">
        <form method="post" action="{{ route('admin.cloud.plans.update', $plan) }}">
            @csrf @method('put')
            @include('admin.partials.cloud-plan-fields', ['plan' => $plan])
            <div>
                <button type="button" class="button button--secondary" onclick="this.closest('dialog').close()">Cancel</button>
                <button class="button button--primary">Save plan</button>
            </div>
        </form>
    </dialog>
@endforeach
</x-admin-layout>
