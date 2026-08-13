<x-dashboard-layout title="Add Server">
    <div x-data="serverWizard()" class="add-server-page">
        <div class="page-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <i data-lucide="chevron-right"></i>
                    <a href="{{ route('servers.index') }}">Servers</a>
                    <i data-lucide="chevron-right"></i>
                    Add Server
                </p>
                <h1>Add custom server <i data-lucide="info"></i></h1>
                <p>Connect your Linux host over SSH, or provision a new one with your own Cloud API.</p>
            </div>
            <div class="heading-actions">
                <a href="{{ route('cloud-api.index') }}" class="button button--secondary"><i data-lucide="key-round"></i> My Cloud API</a>
                <a href="{{ route('servers.index') }}" class="button button--secondary"><i data-lucide="x"></i> Cancel</a>
            </div>
        </div>

        <section class="server-source-picker card">
            <button type="button" :class="mode === 'custom' && 'is-selected'" @click="mode='custom'">
                <span class="section-icon"><i data-lucide="terminal"></i></span>
                <span><strong>Connect custom server</strong><small>Use an IP address and your SSH credentials</small></span>
                <i data-lucide="circle-check" class="source-check"></i>
            </button>
            <button type="button" :class="mode === 'cloud' && 'is-selected'" @click="mode='cloud'">
                <span class="section-icon"><i data-lucide="cloud"></i></span>
                <span><strong>Provision with my Cloud API</strong><small>Create a Droplet or Hetzner server with your own provider token</small></span>
                <i data-lucide="circle-check" class="source-check"></i>
            </button>
        </section>

        <section x-show="mode === 'cloud'" x-cloak class="cloud-server-layout">
            <form method="POST" action="{{ route('servers.cloud.store') }}" class="card cloud-server-form" @submit="cloudSubmitting=true">
                @csrf
                <div class="add-server-card-head add-server-card-head--icon">
                    <span class="section-icon"><i data-lucide="cloud"></i></span>
                    <div><h2>Your Cloud API server</h2><p>Uplary creates the instance with your token, installs its SSH key, and provisions Docker automatically.</p></div>
                </div>
                @if($cloudConnections->isEmpty())
                    <div class="cloud-empty-state"><span class="section-icon"><i data-lucide="key-round"></i></span><h3>Connect your provider API first</h3><p>Add and verify a DigitalOcean or Hetzner API token that belongs to your account before creating a server.</p><a href="{{ route('cloud-api.index') }}" class="button button--primary"><i data-lucide="plug-zap"></i> Connect my Cloud API</a></div>
                @else
                    <div class="add-server-fields add-server-fields--two">
                        <label class="field field--wide"><span>Server name *</span><input name="name" x-model="cloud.name" required placeholder="Production Cloud Server"></label>
                        <label class="field"><span>My API account *</span><select name="provider_connection_id" x-model="cloud.connection" @change="selectCloudConnection()" required><option value="">Select an account</option>@foreach($cloudConnections as $connection)<option value="{{ $connection->id }}" data-provider="{{ $connection->provider }}">{{ $connection->name }} · {{ $connection->provider === 'digitalocean' ? 'DigitalOcean' : 'Hetzner' }}</option>@endforeach</select></label>
                        <label class="field"><span>Server size *</span><select name="managed_server_plan_id" x-model="cloud.plan" @change="selectCloudPlan()" required><option value="">Select a size</option>@foreach($cloudPlans as $plan)<option value="{{ $plan->id }}" data-provider="{{ $plan->provider }}" x-show="!cloud.provider || cloud.provider === '{{ $plan->provider }}'">{{ $plan->name }} · {{ $plan->cpu_cores }} vCPU · {{ round($plan->memory_mb / 1024, 1) }} GB · {{ $plan->disk_gb }} GB disk</option>@endforeach</select></label>
                        <label class="field"><span>Region *</span><select name="region" x-model="cloud.region" required><option value="">Select a region</option><template x-for="region in cloud.regions" :key="region"><option :value="region" x-text="region.toUpperCase()"></option></template></select></label>
                        <label class="field"><span>Operating system *</span><select name="image" x-model="cloud.image" required><option value="">Select an image</option><template x-for="image in cloud.images" :key="image"><option :value="image" x-text="image.replaceAll('-', ' ').replace('ubuntu','Ubuntu').replace('debian','Debian')"></option></template></select></label>
                    </div>
                    <div class="cloud-provision-summary"><i data-lucide="shield-check"></i><span><strong>Your provider account</strong><small>Charges appear on your DigitalOcean or Hetzner bill. Platform-managed APIs are never used for this flow.</small></span></div>
                    <div class="add-server-footer"><a href="{{ route('cloud-api.index') }}" class="button button--secondary"><i data-lucide="settings-2"></i> Manage my API accounts</a><span></span><button class="button button--primary" :disabled="cloudSubmitting"><i data-lucide="rocket"></i><span x-text="cloudSubmitting ? 'Creating server…' : 'Create & provision server'"></span></button></div>
                @endif
            </form>
            <aside class="add-server-aside">
                <article class="card aside-card"><span class="section-icon"><i data-lucide="cloud"></i></span><h3>Supported providers</h3><ul><li><i data-lucide="check"></i> DigitalOcean Droplets API</li><li><i data-lucide="check"></i> Hetzner Cloud Servers API</li><li><i data-lucide="check"></i> Automated IPv4 discovery</li><li><i data-lucide="check"></i> Encrypted tenant tokens only</li></ul></article>
                <article class="card aside-card aside-card--help"><i data-lucide="lock-keyhole" class="aside-help-icon"></i><div><h3>Credentials stay protected</h3><p>Your provider tokens and generated SSH private keys are encrypted at rest and never shown after saving.</p></div></article>
            </aside>
        </section>

        <div class="add-server-stepper add-server-stepper--icons" x-show="mode === 'custom'">
            @foreach([
                ['Server details', 'server', 1],
                ['Connection', 'key-round', 2],
                ['Review', 'clipboard-check', 3],
                ['Provision', 'settings-2', 4],
                ['Success', 'circle-check', 5],
            ] as $index => [$label, $icon, $number])
                <div class="add-server-step" :class="{ 'is-active': step === {{ $number }}, 'is-complete': step > {{ $number }} }">
                    <span class="add-server-step__marker">
                        <i data-lucide="{{ $icon }}" class="step-icon"></i>
                        <i data-lucide="check" class="step-check"></i>
                    </span>
                    <div class="add-server-step__copy">
                        <small>STEP {{ $number }}</small>
                        <strong>{{ $label }}</strong>
                    </div>
                </div>
                @if($number < 5)
                    <span class="add-server-step-line" :class="step > {{ $number }} && 'is-complete'"></span>
                @endif
            @endforeach
        </div>

        <form x-show="mode === 'custom'" method="POST" action="{{ route('servers.store') }}" class="add-server-layout" @submit="submitting=true">
            @csrf
            <section class="add-server-main">
                <article class="card add-server-card" x-show="step === 1" x-transition>
                    <div class="add-server-card-head add-server-card-head--icon">
                        <span class="section-icon"><i data-lucide="server"></i></span>
                        <div>
                            <h2>Server details</h2>
                            <p>Tell us about the server you want to connect.</p>
                        </div>
                    </div>
                    <div class="add-server-fields add-server-fields--two">
                        <label class="field">
                            <span>Server name *</span>
                            <input name="name" x-model="form.name" value="{{ old('name') }}" placeholder="Production Server" required>
                            @error('name')<small class="field-error">{{ $message }}</small>@enderror
                        </label>
                        <label class="field">
                            <span>Provider *</span>
                            <select name="provider" x-model="form.provider" required>
                                @foreach($providers as $provider)
                                    <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field">
                            <span>IP address *</span>
                            <input name="ip_address" x-model="form.ip" value="{{ old('ip_address') }}" placeholder="203.0.113.10" required @input="invalidatePrecheck()">
                            @error('ip_address')<small class="field-error">{{ $message }}</small>@enderror
                        </label>
                        <label class="field">
                            <span>Location</span>
                            <input name="location" x-model="form.location" value="{{ old('location') }}" placeholder="Frankfurt, Germany">
                        </label>
                        <label class="field">
                            <span>Operating system *</span>
                            <select name="operating_system" x-model="form.os" @change="invalidatePrecheck()">
                                <option value="ubuntu-24.04">Ubuntu 24.04 LTS</option>
                                <option value="ubuntu-22.04">Ubuntu 22.04 LTS</option>
                                <option value="debian-12">Debian 12</option>
                            </select>
                        </label>
                        <label class="field">
                            <span>Server group</span>
                            <input name="server_group" x-model="form.group" value="{{ old('server_group') }}" placeholder="Production">
                        </label>
                        <label class="field field--wide">
                            <span>Description</span>
                            <textarea name="description" rows="3" x-model="form.description" placeholder="Primary production application host">{{ old('description') }}</textarea>
                        </label>
                        <label class="field field--wide">
                            <span>Tags</span>
                            <input name="tags" x-model="form.tags" value="{{ old('tags') }}" placeholder="production, web, europe">
                            <small>Separate tags with commas.</small>
                        </label>
                    </div>
                </article>

                <article class="card add-server-card" x-show="step === 2" x-cloak x-transition>
                    <div class="add-server-card-head add-server-card-head--icon">
                        <span class="section-icon"><i data-lucide="key-round"></i></span>
                        <div>
                            <h2>Authorize access</h2>
                            <p>Choose how Uplary authenticates to your server. Credentials are encrypted at rest.</p>
                        </div>
                    </div>

                    <input type="hidden" name="authorization_method" :value="form.auth_mode">
                    <input type="hidden" name="authentication_method" :value="form.auth_mode === 'platform_key' ? 'ssh_key' : form.auth">

                    <div class="authorization-methods">
                        <label class="authorization-method" :class="form.auth_mode === 'platform_key' && 'is-selected'">
                            <input type="radio" value="platform_key" x-model="form.auth_mode" @change="onAuthModeChange()">
                            <i data-lucide="shield-check"></i>
                            <span>
                                <strong>Install platform key</strong>
                                <small>Add Uplary’s public key on the server (root or sudo user)</small>
                            </span>
                        </label>
                        <label class="authorization-method" :class="form.auth_mode === 'credentials' && 'is-selected'">
                            <input type="radio" value="credentials" x-model="form.auth_mode" @change="onAuthModeChange()">
                            <i data-lucide="key-round"></i>
                            <span>
                                <strong>Provide SSH credentials</strong>
                                <small>Paste your private key or password in the control panel</small>
                            </span>
                        </label>
                    </div>

                    <div class="platform-key-panel" x-show="form.auth_mode === 'platform_key'" x-cloak>
                        <div class="platform-key-panel__head">
                            <strong>Authorise Uplary on the server</strong>
                            <p>Run this once as the SSH user (often <code>root</code>, or a sudo user). Non-root users need passwordless sudo for provisioning.</p>
                        </div>
                        <div class="platform-key-command">
                            <code x-text="platformAuthorizeCommand"></code>
                            <button type="button" class="button button--secondary" @click="copyText(platformAuthorizeCommand, 'command')">
                                <i data-lucide="copy"></i>
                                <span x-text="copied === 'command' ? 'Copied' : 'Copy'"></span>
                            </button>
                        </div>
                        <label class="field field--wide">
                            <span>Uplary public key</span>
                            <div class="platform-key-public">
                                <textarea readonly rows="3" x-model="platformPublicKey">{{ $platformPublicKey }}</textarea>
                                <button type="button" class="button button--secondary" @click="copyText(platformPublicKey, 'pubkey')">
                                    <i data-lucide="copy"></i>
                                    <span x-text="copied === 'pubkey' ? 'Copied' : 'Copy key'"></span>
                                </button>
                            </div>
                            <small>The matching private key stays encrypted on the platform and is used for the connection test after you install the public key.</small>
                        </label>
                    </div>

                    <div class="connection-type" x-show="form.auth_mode === 'credentials'" x-cloak>
                        <label :class="form.auth === 'ssh_key' && 'is-selected'">
                            <input type="radio" value="ssh_key" x-model="form.auth" @change="invalidatePrecheck()">
                            <i data-lucide="key-round"></i>
                            <span><strong>SSH key</strong><small>Paste your private key here</small></span>
                        </label>
                        <label :class="form.auth === 'password' && 'is-selected'">
                            <input type="radio" value="password" x-model="form.auth" @change="invalidatePrecheck()">
                            <i data-lucide="lock-keyhole"></i>
                            <span><strong>Password</strong><small>Use an existing sudo password</small></span>
                        </label>
                    </div>

                    <div class="add-server-fields add-server-fields--two">
                        <label class="field">
                            <span>SSH username *</span>
                            <input name="ssh_username" x-model="form.user" value="{{ old('ssh_username', 'root') }}" required @input="invalidatePrecheck()">
                            <small x-show="form.user !== 'root'">Non-root users must have passwordless sudo.</small>
                        </label>
                        <label class="field">
                            <span>SSH port *</span>
                            <input type="number" name="ssh_port" x-model="form.port" value="{{ old('ssh_port', 22) }}" min="1" max="65535" required @input="invalidatePrecheck()">
                        </label>
                        <label class="field field--wide" x-show="form.auth_mode === 'credentials' && form.auth === 'ssh_key'" x-cloak>
                            <span>Private key *</span>
                            <textarea name="private_key" rows="8" x-model="form.private_key" :required="form.auth_mode === 'credentials' && form.auth === 'ssh_key'" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" @input="invalidatePrecheck()"></textarea>
                            <small>Inserted from the control panel — never shown again after submission.</small>
                        </label>
                        <label class="field field--wide" x-show="form.auth_mode === 'credentials' && form.auth === 'password'" x-cloak>
                            <span>Password *</span>
                            <input type="password" name="password" x-model="form.password" :required="form.auth_mode === 'credentials' && form.auth === 'password'" autocomplete="new-password" placeholder="Enter the server password" @input="invalidatePrecheck()">
                        </label>
                        <label class="field" x-show="form.auth_mode === 'credentials' && form.auth === 'ssh_key'" x-cloak>
                            <span>Key passphrase</span>
                            <input type="password" name="passphrase" x-model="form.passphrase" autocomplete="off" placeholder="Optional" @input="invalidatePrecheck()">
                        </label>
                        <label class="field">
                            <span>Connection timeout</span>
                            <select name="connection_timeout" x-model="form.timeout" @change="invalidatePrecheck()">
                                <option value="15">15 seconds</option>
                                <option value="30">30 seconds</option>
                                <option value="60">60 seconds</option>
                            </select>
                        </label>
                    </div>

                    <div class="aside-note add-server-inline-note">
                        <i data-lucide="shield-check"></i>
                        <div>
                            <strong>Connection pre-check</strong>
                            <span x-show="form.auth_mode === 'platform_key'">After installing the public key, continue to verify SSH with the platform private key, sudo, OS, and resources.</span>
                            <span x-show="form.auth_mode === 'credentials'">When you continue, we validate the host with the credentials you inserted, plus sudo, OS, CPU, memory, disk, and Docker.</span>
                        </div>
                    </div>

                    <div class="connection-precheck" x-show="precheck.testing || precheck.checks.length" x-cloak>
                        <div class="connection-precheck__head">
                            <strong x-text="precheck.testing ? 'Running connection pre-check…' : (precheck.passed ? 'Connection pre-check passed' : 'Connection pre-check failed')"></strong>
                            <small x-show="!precheck.testing && precheck.message" x-text="precheck.message"></small>
                            <small x-show="!precheck.testing && precheck.driver === 'fake'" class="precheck-driver-note">Fake driver — simulated results. Set INFRASTRUCTURE_DRIVER=ssh for a live SSH check.</small>
                            <small x-show="!precheck.testing && precheck.driver === 'ssh'" class="precheck-driver-note">Live SSH check against the host above.</small>
                        </div>
                        <div class="precheck-panel add-server-precheck" :class="precheck.passed ? 'is-passed' : 'is-failed'">
                            <template x-for="check in precheck.checks" :key="check.key">
                                <div class="precheck-row" :class="check.passed ? 'is-ok' : 'is-bad'">
                                    <span>
                                        <i :data-lucide="check.passed ? 'circle-check' : 'circle-x'"></i>
                                        <span x-text="check.label"></span>
                                    </span>
                                    <em x-text="check.message"></em>
                                </div>
                            </template>
                        </div>
                    </div>
                </article>

                <article class="card add-server-card" x-show="step === 3" x-cloak x-transition>
                    <div class="add-server-card-head add-server-card-head--icon">
                        <span class="section-icon"><i data-lucide="clipboard-check"></i></span>
                        <div>
                            <h2>Review server</h2>
                            <p>Confirm the connection and installation options.</p>
                        </div>
                    </div>

                    <div class="review-stack">
                        <section class="review-card">
                            <div class="review-card-head">
                                <h3>Server information</h3>
                                <button type="button" @click="step=1">Edit</button>
                            </div>
                            <dl>
                                <div><dt>Name</dt><dd x-text="form.name || '—'"></dd></div>
                                <div><dt>Provider</dt><dd x-text="form.provider"></dd></div>
                                <div><dt>IP address</dt><dd x-text="form.ip || '—'"></dd></div>
                                <div><dt>Location</dt><dd x-text="form.location || '—'"></dd></div>
                                <div><dt>Operating system</dt><dd x-text="form.os"></dd></div>
                            </dl>
                        </section>

                        <section class="review-card">
                            <div class="review-card-head">
                                <h3>Connection</h3>
                                <button type="button" @click="step=2">Edit</button>
                            </div>
                            <dl>
                                <div><dt>Authorization</dt><dd x-text="form.auth_mode === 'platform_key' ? 'Platform key' : 'Credentials in panel'"></dd></div>
                                <div><dt>Method</dt><dd x-text="form.auth_mode === 'platform_key' ? 'SSH key (Uplary)' : (form.auth === 'ssh_key' ? 'SSH key' : 'Password')"></dd></div>
                                <div><dt>Username</dt><dd x-text="form.user"></dd></div>
                                <div><dt>Port</dt><dd x-text="form.port"></dd></div>
                                <div><dt>Credentials</dt><dd>••••••••••••</dd></div>
                            </dl>
                        </section>

                        <section class="review-card">
                            <div class="review-card-head">
                                <h3>Installation options</h3>
                            </div>
                            <div class="install-options" style="padding:14px 18px 18px">
                                <label><input type="checkbox" name="install_docker" value="1" checked><span><strong>Install Docker Engine</strong><small>Latest stable engine and Compose plugin</small></span></label>
                                <label><input type="checkbox" name="install_proxy" value="1" checked><span><strong>Install reverse proxy</strong><small>Traefik routing and automatic SSL readiness</small></span></label>
                                <label><input type="checkbox" name="install_monitoring" value="1" checked><span><strong>Configure monitoring</strong><small>Server and container resource collection</small></span></label>
                            </div>
                        </section>
                    </div>

                    <div class="precheck-panel add-server-precheck" :class="precheck.passed ? 'is-passed' : 'is-failed'">
                        <template x-for="check in precheck.checks" :key="'review-'+check.key">
                            <div class="precheck-row" :class="check.passed ? 'is-ok' : 'is-bad'">
                                <span>
                                    <i :data-lucide="check.passed ? 'circle-check' : 'circle-x'"></i>
                                    <span x-text="check.label"></span>
                                </span>
                                <em x-text="check.message"></em>
                            </div>
                        </template>
                        <div class="precheck-row is-bad" x-show="!precheck.checks.length">
                            <span><i data-lucide="circle-x"></i> Connection pre-check required</span>
                        </div>
                    </div>
                </article>

                <div class="add-server-footer">
                    <button type="button" class="button button--secondary" x-show="step > 1" @click="back()" :disabled="precheck.testing"><i data-lucide="arrow-left"></i> Back</button>
                    <span style="flex:1"></span>
                    <button type="button" class="button button--secondary" x-show="step === 2 && precheck.checks.length && !precheck.passed" @click="runPrecheck()" :disabled="precheck.testing">
                        <i data-lucide="refresh-cw"></i> Retest connection
                    </button>
                    <button type="button" class="button button--primary" x-show="step < 3" @click="next()" :disabled="precheck.testing">
                        <span x-show="step === 2 && precheck.testing"><i data-lucide="loader-circle" class="spin"></i> Testing connection…</span>
                        <span x-show="!(step === 2 && precheck.testing)">Continue <i data-lucide="arrow-right"></i></span>
                    </button>
                    <button type="submit" class="button button--primary" x-show="step === 3" :disabled="submitting || !precheck.passed" @click="guardSubmit($event)">
                        <i data-lucide="server-cog"></i>
                        <span x-text="submitting ? 'Adding server…' : 'Add server & provision'"></span>
                    </button>
                </div>
            </section>

            <aside class="add-server-aside">
                <article class="card aside-card">
                    <span class="section-icon"><i data-lucide="badge-check"></i></span>
                    <h3>Server requirements</h3>
                    <ul>
                        <li><i data-lucide="check"></i> Ubuntu 22.04 / 24.04 or Debian 12</li>
                        <li><i data-lucide="check"></i> Root or passwordless sudo access</li>
                        <li><i data-lucide="check"></i> Minimum 1 CPU and 2 GB RAM</li>
                        <li><i data-lucide="check"></i> Ports 22, 80, and 443 reachable</li>
                    </ul>
                </article>
                <article class="card aside-card aside-card--help">
                    <i data-lucide="lightbulb" class="aside-help-icon"></i>
                    <div>
                        <h3>Bring your own server</h3>
                        <p>The platform remains the control plane. Your applications and data stay on the connected host.</p>
                    </div>
                </article>
                <article class="card aside-card">
                    <span class="section-icon"><i data-lucide="cloud-cog"></i></span>
                    <h3>Provision from a cloud API</h3>
                    <p>Connect a DigitalOcean or Hetzner API token that you own, choose a region and size, and let Uplary create the server and its SSH credentials.</p>
                    <a href="{{ route('cloud-api.index') }}" class="button button--secondary" style="width:100%;justify-content:center">Manage my Cloud API</a>
                </article>
                <article class="card aside-card" x-show="form.auth_mode === 'platform_key'">
                    <span class="section-icon"><i data-lucide="terminal"></i></span>
                    <h3>Install the platform key</h3>
                    <p>Paste the one-liner on the server as the SSH user. Root is not required if that user has passwordless sudo.</p>
                    <p>Uplary keeps the matching private key encrypted and never asks you to paste it.</p>
                </article>
                <article class="card aside-card" x-show="form.auth_mode === 'credentials'">
                    <span class="section-icon"><i data-lucide="key-round"></i></span>
                    <h3>Insert SSH in the panel</h3>
                    <p>Paste your private key or password here when you cannot install Uplary’s public key (for example no root shell to edit authorized_keys).</p>
                    <p>For non-root users, enable passwordless sudo for provisioning.</p>
                </article>
            </aside>
        </form>
    </div>

    <script>
        function serverWizard() {
            return {
                mode: @js(old('provider_connection_id') ? 'cloud' : 'custom'),
                step: 1,
                submitting: false,
                cloudSubmitting: false,
                validateUrl: @js(route('servers.validate-connection')),
                csrf: @js(csrf_token()),
                platformPublicKey: @js($platformPublicKey),
                platformAuthorizeCommand: @js($platformAuthorizeCommand),
                copied: null,
                precheck: { testing: false, passed: false, message: '', driver: null, checks: [] },
                cloud: { name: @js(old('name', '')), connection: @js((string) old('provider_connection_id', '')), provider: '', plan: @js((string) old('managed_server_plan_id', '')), region: @js(old('region', '')), image: @js(old('image', 'ubuntu-24.04')), regions: [], images: [] },
                plans: @js($cloudPlans->mapWithKeys(fn($plan) => [(string) $plan->id => ['provider' => $plan->provider, 'regions' => $plan->regions, 'images' => $plan->images]])),
                form: {
                    name: @js(old('name', '')),
                    provider: @js(old('provider', 'custom')),
                    ip: @js(old('ip_address', '')),
                    location: @js(old('location', '')),
                    os: @js(old('operating_system', 'ubuntu-24.04')),
                    group: @js(old('server_group', '')),
                    description: @js(old('description', '')),
                    tags: @js(old('tags', '')),
                    auth_mode: @js(old('authorization_method', 'credentials')),
                    auth: @js(old('authentication_method', 'ssh_key')),
                    user: @js(old('ssh_username', 'root')),
                    port: @js(old('ssh_port', 22)),
                    private_key: '',
                    password: '',
                    passphrase: '',
                    timeout: @js((string) old('connection_timeout', '30')),
                },
                invalidatePrecheck() {
                    this.precheck = { testing: false, passed: false, message: '', driver: null, checks: [] };
                },
                onAuthModeChange() {
                    if (this.form.auth_mode === 'platform_key') {
                        this.form.auth = 'ssh_key';
                    }
                    this.invalidatePrecheck();
                    this.refreshIcons();
                },
                async copyText(value, key) {
                    try {
                        await navigator.clipboard.writeText(value);
                        this.copied = key;
                        setTimeout(() => { if (this.copied === key) this.copied = null; }, 1800);
                    } catch (e) {
                        this.copied = null;
                    }
                },
                refreshIcons() {
                    this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
                },
                back() {
                    if (this.step > 1) this.step--;
                },
                async next() {
                    if (this.step === 1) {
                        if (!this.form.name || !this.form.ip) {
                            document.querySelector('[name=name]')?.reportValidity();
                            document.querySelector('[name=ip_address]')?.reportValidity();
                            return;
                        }
                        this.step = 2;
                        this.refreshIcons();
                        return;
                    }
                    if (this.step === 2) {
                        if (!this.connectionFieldsValid()) return;
                        if (this.precheck.passed) {
                            this.step = 3;
                            this.refreshIcons();
                            return;
                        }
                        const ok = await this.runPrecheck();
                        if (ok) {
                            this.step = 3;
                            this.refreshIcons();
                        }
                    }
                },
                connectionFieldsValid() {
                    if (!this.form.user || !this.form.port) {
                        document.querySelector('[name=ssh_username]')?.reportValidity();
                        document.querySelector('[name=ssh_port]')?.reportValidity();
                        return false;
                    }
                    if (this.form.auth_mode === 'credentials' && this.form.auth === 'ssh_key' && !this.form.private_key.trim()) {
                        document.querySelector('[name=private_key]')?.reportValidity();
                        this.precheck = { testing: false, passed: false, message: 'A private key is required.', driver: null, checks: [] };
                        return false;
                    }
                    if (this.form.auth_mode === 'credentials' && this.form.auth === 'password' && !this.form.password) {
                        document.querySelector('[name=password]')?.reportValidity();
                        this.precheck = { testing: false, passed: false, message: 'A password is required.', driver: null, checks: [] };
                        return false;
                    }
                    return true;
                },
                async runPrecheck() {
                    if (!this.connectionFieldsValid()) return false;
                    this.precheck = { testing: true, passed: false, message: '', driver: null, checks: [] };
                    try {
                        const usingPlatform = this.form.auth_mode === 'platform_key';
                        const response = await fetch(this.validateUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                ip_address: this.form.ip,
                                operating_system: this.form.os,
                                ssh_port: Number(this.form.port),
                                ssh_username: this.form.user,
                                authorization_method: this.form.auth_mode,
                                authentication_method: usingPlatform ? 'ssh_key' : this.form.auth,
                                private_key: (!usingPlatform && this.form.auth === 'ssh_key') ? this.form.private_key : null,
                                password: (!usingPlatform && this.form.auth === 'password') ? this.form.password : null,
                                passphrase: (!usingPlatform && this.form.passphrase) ? this.form.passphrase : null,
                                connection_timeout: Number(this.form.timeout),
                                install_docker: true,
                            }),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
                            this.precheck = {
                                testing: false,
                                passed: false,
                                message: firstError || data.message || 'Connection pre-check failed.',
                                driver: data.driver || null,
                                checks: Array.isArray(data.checks) ? data.checks : [],
                            };
                            this.refreshIcons();
                            return false;
                        }
                        this.precheck = {
                            testing: false,
                            passed: !!data.success,
                            message: data.message || '',
                            driver: data.driver || null,
                            checks: Array.isArray(data.checks) ? data.checks : [],
                        };
                        this.refreshIcons();
                        return !!data.success;
                    } catch (error) {
                        this.precheck = {
                            testing: false,
                            passed: false,
                            message: 'Could not reach the connection pre-check endpoint.',
                            driver: null,
                            checks: [],
                        };
                        return false;
                    }
                },
                guardSubmit(event) {
                    if (!this.precheck.passed) {
                        event.preventDefault();
                        this.step = 2;
                        this.refreshIcons();
                    }
                },
                selectCloudConnection() {
                    const option = this.$root.querySelector('[name=provider_connection_id] option:checked');
                    this.cloud.provider = option?.dataset.provider || '';
                    const selectedPlan = this.plans[this.cloud.plan];
                    if (!selectedPlan || selectedPlan.provider !== this.cloud.provider) this.cloud.plan = '';
                    this.selectCloudPlan();
                },
                selectCloudPlan() {
                    const plan = this.plans[this.cloud.plan];
                    this.cloud.regions = plan?.regions || [];
                    this.cloud.images = plan?.images || [];
                    if (!this.cloud.regions.includes(this.cloud.region)) this.cloud.region = this.cloud.regions[0] || '';
                    if (!this.cloud.images.includes(this.cloud.image)) this.cloud.image = this.cloud.images[0] || '';
                },
                init() { this.selectCloudConnection(); },
            };
        }
    </script>
</x-dashboard-layout>
