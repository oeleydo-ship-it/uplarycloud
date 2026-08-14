<x-dashboard-layout title="My Cloud API">
    <div class="cloud-api-page">
        <div class="page-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <i data-lucide="chevron-right"></i>
                    <a href="{{ route('servers.index') }}">Servers</a>
                    <i data-lucide="chevron-right"></i>
                    Cloud API
                </p>
                <h1>My Cloud API</h1>
                <p>Connect your own DigitalOcean or Hetzner tokens. These credentials stay in your workspace and are never mixed with platform-managed APIs.</p>
            </div>
            <div class="heading-actions">
                <a href="{{ route('servers.create') }}" class="button button--secondary"><i data-lucide="arrow-left"></i> Back to Add Server</a>
            </div>
        </div>

        <div class="cloud-api-layout">
            <section class="card">
                <div class="add-server-card-head add-server-card-head--icon">
                    <span class="section-icon"><i data-lucide="key-round"></i></span>
                    <div>
                        <h2>Connected accounts</h2>
                        <p>Tokens are encrypted at rest and never shown after saving.</p>
                    </div>
                </div>

                @forelse($connections as $connection)
                    <article class="cloud-api-row">
                        <span class="cloud-provider-logo">{{ $connection->provider === 'digitalocean' ? 'DO' : 'HZ' }}</span>
                        <div>
                            <strong>{{ $connection->name }}</strong>
                            <small>{{ $connection->provider === 'digitalocean' ? 'DigitalOcean' : 'Hetzner Cloud' }} · {{ $connection->last_verified_at ? 'Verified '.$connection->last_verified_at->diffForHumans() : 'Not verified' }}</small>
                            @if($connection->last_error)
                                <em class="cloud-api-error">{{ str($connection->last_error)->limit(90) }}</em>
                            @endif
                        </div>
                        <div class="cloud-api-actions">
                            <form method="POST" action="{{ route('managed.connections.verify', $connection) }}">
                                @csrf
                                <button class="button button--secondary" type="submit"><i data-lucide="badge-check"></i> Verify</button>
                            </form>
                            <form method="POST" action="{{ route('managed.connections.destroy', $connection) }}" onsubmit="return confirm('Delete these provider API credentials?')">
                                @csrf @method('DELETE')
                                <button class="button button--danger" type="submit" title="Delete provider API"><i data-lucide="trash-2"></i> Remove</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="cloud-empty-state">
                        <span class="section-icon"><i data-lucide="plug-zap"></i></span>
                        <h3>No Cloud API connected yet</h3>
                        <p>Add a DigitalOcean or Hetzner token to provision servers billed directly by your provider account.</p>
                    </div>
                @endforelse
            </section>

            <section class="card">
                <div class="add-server-card-head add-server-card-head--icon">
                    <span class="section-icon"><i data-lucide="plus"></i></span>
                    <div>
                        <h2>Connect provider API</h2>
                        <p>Your token is used only for servers you create in this workspace.</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('managed.connections.store') }}" class="add-server-fields">
                    @csrf
                    <label class="field">
                        <span>Account name *</span>
                        <input name="name" required placeholder="Production DigitalOcean" value="{{ old('name') }}">
                        @error('name')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="field">
                        <span>Provider *</span>
                        <select name="provider" required>
                            <option value="digitalocean" @selected(old('provider') === 'digitalocean')>DigitalOcean</option>
                            <option value="hetzner" @selected(old('provider') === 'hetzner')>Hetzner Cloud</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>API token *</span>
                        <input type="password" name="api_token" required autocomplete="new-password" placeholder="Paste API token">
                        @error('api_token')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="field">
                        <span>Account / project ID</span>
                        <input name="account_id" value="{{ old('account_id') }}" placeholder="Optional">
                    </label>
                    <button class="button button--primary" type="submit"><i data-lucide="lock-keyhole"></i> Save encrypted token</button>
                </form>
            </section>
        </div>
    </div>
</x-dashboard-layout>
