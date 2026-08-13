<x-dashboard-layout title="Add Managed Server">
    <div x-data="managedServerWizard()" class="add-server-page">
        <div class="page-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <i data-lucide="chevron-right"></i>
                    <a href="{{ route('servers.index') }}">Servers</a>
                    <i data-lucide="chevron-right"></i>
                    Add Managed Server
                </p>
                <h1>Add managed server <i data-lucide="info"></i></h1>
                <p>Pick a region and size. Uplary provisions the instance with platform cloud credentials — API tokens stay with the platform.</p>
            </div>
            <div class="heading-actions">
                <a href="{{ route('managed.index') }}" class="button button--secondary"><i data-lucide="cloud-cog"></i> Catalog</a>
                <a href="{{ route('servers.index') }}" class="button button--secondary"><i data-lucide="x"></i> Cancel</a>
            </div>
        </div>

        <section class="cloud-server-layout">
            <form method="POST" action="{{ route('managed.servers.store') }}" class="card cloud-server-form" @submit="cloudSubmitting=true">
                @csrf
                <div class="add-server-card-head add-server-card-head--icon">
                    <span class="section-icon"><i data-lucide="cloud-cog"></i></span>
                    <div>
                        <h2>Managed cloud server</h2>
                        <p>Choose a published plan. Billing is handled by the platform — you never see provider API tokens.</p>
                    </div>
                </div>

                @if($cloudConnections->isEmpty())
                    <div class="cloud-empty-state">
                        <span class="section-icon"><i data-lucide="cloud-off"></i></span>
                        <h3>Managed cloud is unavailable</h3>
                        <p>The platform administrator has not published a verified DigitalOcean or Hetzner connection yet.</p>
                    </div>
                @else
                    <div class="add-server-fields add-server-fields--two">
                        <label class="field field--wide">
                            <span>Server name *</span>
                            <input name="name" x-model="cloud.name" required placeholder="Production Managed Server">
                        </label>
                        <label class="field">
                            <span>Platform provider *</span>
                            <select name="provider_connection_id" x-model="cloud.connection" @change="selectCloudConnection()" required>
                                <option value="">Select a provider</option>
                                @foreach($cloudConnections as $connection)
                                    <option value="{{ $connection->id }}" data-provider="{{ $connection->provider }}">
                                        {{ $connection->provider === 'digitalocean' ? 'DigitalOcean' : 'Hetzner Cloud' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field">
                            <span>Server plan *</span>
                            <select name="managed_server_plan_id" x-model="cloud.plan" @change="selectCloudPlan()" required>
                                <option value="">Select a plan</option>
                                @foreach($cloudPlans as $plan)
                                    <option
                                        value="{{ $plan->id }}"
                                        data-provider="{{ $plan->provider }}"
                                        x-show="!cloud.provider || cloud.provider === '{{ $plan->provider }}'"
                                    >
                                        {{ $plan->name }} · {{ $plan->cpu_cores }} vCPU · {{ round($plan->memory_mb / 1024, 1) }} GB · {{ $plan->priceLabel() }}/mo
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field">
                            <span>Region *</span>
                            <select name="region" x-model="cloud.region" required>
                                <option value="">Select a region</option>
                                <template x-for="region in cloud.regions" :key="region">
                                    <option :value="region" x-text="region.toUpperCase()"></option>
                                </template>
                            </select>
                        </label>
                        <label class="field">
                            <span>Operating system *</span>
                            <select name="image" x-model="cloud.image" required>
                                <option value="">Select an image</option>
                                <template x-for="image in cloud.images" :key="image">
                                    <option :value="image" x-text="image.replaceAll('-', ' ').replace('ubuntu','Ubuntu').replace('debian','Debian')"></option>
                                </template>
                            </select>
                        </label>
                    </div>
                    <div class="cloud-provision-summary">
                        <i data-lucide="shield-check"></i>
                        <span>
                            <strong>Platform-managed credentials</strong>
                            <small>Provider tokens are configured by superadmins only. Your workspace never receives platform API secrets.</small>
                        </span>
                    </div>
                    <div class="add-server-footer">
                        <a href="{{ route('managed.index') }}" class="button button--secondary"><i data-lucide="layout-grid"></i> Browse catalog</a>
                        <span></span>
                        <button class="button button--primary" :disabled="cloudSubmitting">
                            <i data-lucide="rocket"></i>
                            <span x-text="cloudSubmitting ? 'Creating server…' : 'Create & provision server'"></span>
                        </button>
                    </div>
                @endif
            </form>
            <aside class="add-server-aside">
                <article class="card aside-card">
                    <span class="section-icon"><i data-lucide="cloud"></i></span>
                    <h3>What you choose</h3>
                    <ul>
                        <li><i data-lucide="check"></i> Provider and plan</li>
                        <li><i data-lucide="check"></i> Region and OS image</li>
                        <li><i data-lucide="check"></i> Server display name</li>
                    </ul>
                </article>
                <article class="card aside-card aside-card--help">
                    <i data-lucide="lock-keyhole" class="aside-help-icon"></i>
                    <div>
                        <h3>Tokens stay on the platform</h3>
                        <p>Managed servers use superadmin-configured DigitalOcean or Hetzner accounts. Use <a href="{{ route('servers.create') }}">custom own server</a> to bring your own Cloud API.</p>
                    </div>
                </article>
            </aside>
        </section>
    </div>

    <script>
        function managedServerWizard() {
            return {
                cloudSubmitting: false,
                cloud: {
                    name: @js(old('name', '')),
                    connection: @js((string) old('provider_connection_id', '')),
                    provider: '',
                    plan: @js((string) old('managed_server_plan_id', '')),
                    region: @js(old('region', '')),
                    image: @js(old('image', 'ubuntu-24.04')),
                    regions: [],
                    images: [],
                },
                plans: @js($cloudPlans->mapWithKeys(fn ($plan) => [(string) $plan->id => ['provider' => $plan->provider, 'regions' => $plan->regions, 'images' => $plan->images]])),
                init() {
                    if (this.cloud.connection) this.selectCloudConnection();
                    if (this.cloud.plan) this.selectCloudPlan();
                    this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
                },
                selectCloudConnection() {
                    const option = document.querySelector(`select[name=provider_connection_id] option[value="${this.cloud.connection}"]`);
                    this.cloud.provider = option?.dataset.provider || '';
                    if (this.cloud.plan && this.plans[this.cloud.plan]?.provider !== this.cloud.provider) {
                        this.cloud.plan = '';
                        this.cloud.regions = [];
                        this.cloud.images = [];
                    }
                },
                selectCloudPlan() {
                    const plan = this.plans[this.cloud.plan];
                    this.cloud.regions = plan?.regions || [];
                    this.cloud.images = plan?.images || [];
                    if (!this.cloud.regions.includes(this.cloud.region)) this.cloud.region = this.cloud.regions[0] || '';
                    if (!this.cloud.images.includes(this.cloud.image)) this.cloud.image = this.cloud.images[0] || '';
                },
            };
        }
    </script>
</x-dashboard-layout>
