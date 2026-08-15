<x-dashboard-layout title="Managed Servers">
    <div
        class="managed-page"
        x-data="managedCatalog(@js($connections->values()), @js($plans->flatten()->values()), {{ $errors->any() ? 'true' : 'false' }}, @js((string) old('managed_server_plan_id', '')))"
    >
        <div class="page-heading">
            <div>
                <p class="breadcrumb">Infrastructure / Managed Servers</p>
                <h1>Managed servers</h1>
                <p>Deploy production-ready servers with infrastructure operated by the platform.</p>
            </div>
            <button
                class="button button--primary"
                @click="serverOpen=true"
                @disabled($connections->isEmpty() || $plans->flatten()->isEmpty())
            >
                <i data-lucide="cloud-cog"></i>Create managed server
            </button>
        </div>

        @if(request('checkout') === 'success')
            <div class="commercial-notice commercial-notice--success"><i data-lucide="circle-check"></i>Payment received. Your managed server will begin provisioning once Stripe confirms the payment.</div>
        @elseif(request('checkout') === 'canceled')
            <div class="commercial-notice commercial-notice--warning"><i data-lucide="circle-alert"></i>Managed server checkout was canceled. No payment was taken.</div>
        @endif

        @error('payment')
            <div class="commercial-notice commercial-notice--error"><i data-lucide="circle-alert"></i>{{ $message }}</div>
        @enderror

        @if($connections->isEmpty() || $plans->flatten()->isEmpty())
            <section class="card managed-unavailable">
                <span class="section-icon"><i data-lucide="cloud-off"></i></span>
                <div>
                    <h2>Managed servers are currently unavailable</h2>
                    <p>The platform administrator has not published managed-server capacity yet.</p>
                </div>
            </section>
        @endif

        <section class="managed-summary-grid">
            <x-stat-card label="Managed Servers" :value="$servers->count()" :detail="$servers->where('status.value', 'online')->count().' online'" icon="server-cog" tone="purple" />
            <x-stat-card label="Managed Service" value="Ready" detail="Platform operated" icon="shield-check" tone="blue" />
            <x-stat-card label="Available Plans" :value="$plans->flatten()->count()" detail="Managed pricing" icon="badge-dollar-sign" tone="green" />
            <x-stat-card label="Provisioning" :value="$servers->whereIn('status.value', ['pending', 'provisioning'])->count()" detail="In progress" icon="loader-circle" tone="orange" />
        </section>

        <section class="card managed-catalog">
            <div class="card-head">
                <div>
                    <h2>Managed server catalog</h2>
                    <p>Monthly prices include infrastructure management and support.</p>
                </div>
            </div>
            <div class="managed-plan-grid">
                @foreach($plans->flatten() as $plan)
                    <article>
                        <div>
                            <span class="provider-badge">MS</span>
                            @if($plan->featured)<em>Recommended</em>@endif
                        </div>
                        <h3>{{ $plan->name }}</h3>
                        <p>Managed server</p>
                        <strong>{{ $plan->priceLabel() }}<small>/month</small></strong>
                        <ul>
                            <li>{{ $plan->cpu_cores }} vCPU</li>
                            <li>{{ round($plan->memory_mb / 1024, 1) }} GB RAM</li>
                            <li>{{ $plan->disk_gb }} GB SSD</li>
                            <li>{{ number_format($plan->bandwidth_gb) }} GB transfer</li>
                        </ul>
                        <button class="button button--secondary button--full" @click="choosePlan('{{ $plan->id }}')">Select plan</button>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="card managed-server-table">
            <div class="card-head">
                <div>
                    <h2>Your managed servers</h2>
                    <p>Lifecycle, capacity, and provisioning status.</p>
                </div>
            </div>
            @forelse($servers as $server)
                <a href="{{ route('servers.show', $server) }}" class="managed-server-row">
                    <span class="section-icon"><i data-lucide="server"></i></span>
                    <span>
                        <strong>{{ $server->name }}</strong>
                        <small>Managed server · {{ strtoupper($server->provider_region) }} · {{ $server->ip_address }}</small>
                    </span>
                    <span>{{ $server->managedPlan?->name }}<small>{{ $server->managedPlan?->priceLabel() }}/mo</small></span>
                    <span class="status status--{{ $server->status->tone() }}"><i></i>{{ $server->status->label() }}</span>
                    <i data-lucide="chevron-right"></i>
                </a>
            @empty
                <div class="empty-state">
                    <i data-lucide="cloud-cog"></i>
                    <h3>No managed servers yet</h3>
                    <p>Choose a managed plan to create your first server.</p>
                </div>
            @endforelse
        </section>

        <div class="modal-backdrop" x-show="serverOpen" x-cloak>
            <section class="domain-modal managed-create-modal managed-create-redesign" @click.outside="serverOpen=false">
                <div class="domain-modal-head">
                    <div>
                        <span class="section-icon"><i data-lucide="cloud-cog"></i></span>
                        <div>
                            <h2>Create managed server</h2>
                            <p>Choose a plan, location, and operating system.</p>
                        </div>
                    </div>
                    <button type="button" @click="serverOpen=false" aria-label="Close"><i data-lucide="x"></i></button>
                </div>
                <form method="post" action="{{ route('managed.servers.store') }}">
                    @csrf
                    <div class="domain-modal-body managed-form-body">
                        <div class="managed-service-note">
                            <span><i data-lucide="shield-check"></i></span>
                            <div>
                                <strong>Fully managed infrastructure</strong>
                                <small>Connection, provisioning, monitoring, and maintenance are handled automatically. You will be redirected to secure checkout to pay the first month before the server is created.</small>
                            </div>
                        </div>

                        <label class="field managed-field--wide">
                            <span>Server name</span>
                            <input name="name" value="{{ old('name') }}" required placeholder="Production server 01">
                            <small>Use a clear name that identifies this server in your workspace.</small>
                        </label>

                        <input type="hidden" name="provider_connection_id" :value="selectedConnection">

                        <label class="field managed-field--wide">
                            <span>Managed server plan</span>
                            <select name="managed_server_plan_id" x-model="selectedPlan" @change="syncPlan()" required>
                                <option value="">Choose a managed plan</option>
                                <template x-for="plan in availablePlans" :key="plan.id">
                                    <option :value="plan.id" x-text="`${plan.name} · ${money(plan.monthly_price)}/month`"></option>
                                </template>
                            </select>
                        </label>

                        <div class="managed-location-grid">
                            <label class="field">
                                <span>Location</span>
                                <select name="region" x-model="region" required :disabled="!selectedPlan">
                                    <option value="">Choose a location</option>
                                    <template x-for="item in regions" :key="item">
                                        <option :value="item" x-text="formatRegion(item)"></option>
                                    </template>
                                </select>
                            </label>
                            <label class="field">
                                <span>Operating system</span>
                                <select name="image" x-model="image" required :disabled="!selectedPlan">
                                    <option value="">Choose an operating system</option>
                                    <template x-for="item in images" :key="item">
                                        <option :value="item" x-text="formatImage(item)"></option>
                                    </template>
                                </select>
                            </label>
                        </div>

                        <div class="managed-price-summary" x-show="currentPlan" x-transition>
                            <span>
                                <small>Monthly managed server</small>
                                <strong x-text="currentPlan ? money(currentPlan.monthly_price) : '$0.00'"></strong>
                            </span>
                            <p>Includes infrastructure, provisioning, monitoring, and platform management.</p>
                        </div>
                    </div>
                    <div class="domain-modal-actions">
                        <button type="button" class="button button--secondary" @click="serverOpen=false">Cancel</button>
                        <button class="button button--primary" :disabled="!selectedConnection || !selectedPlan || !region || !image">
                            <i data-lucide="rocket"></i>Create managed server
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </div>

    <script>
        function managedCatalog(connections, plans, open, initialPlan) {
            return {
                connections,
                plans,
                serverOpen: open,
                selectedConnection: '',
                selectedPlan: initialPlan,
                region: '',
                image: '',
                regions: [],
                images: [],
                get availablePlans() {
                    return this.plans.filter(plan => this.connections.some(connection => connection.provider === plan.provider));
                },
                get currentPlan() {
                    return this.plans.find(plan => String(plan.id) === String(this.selectedPlan));
                },
                init() {
                    if (this.selectedPlan) this.syncPlan();
                },
                choosePlan(id) {
                    this.selectedPlan = String(id);
                    this.syncPlan();
                    this.serverOpen = true;
                },
                syncPlan() {
                    const plan = this.currentPlan;
                    const connection = plan
                        ? this.connections.find(item => item.provider === plan.provider)
                        : null;

                    this.selectedConnection = connection ? String(connection.id) : '';
                    this.regions = plan?.regions || [];
                    this.images = plan?.images || [];
                    this.region = this.regions[0] || '';
                    this.image = this.images[0] || '';
                },
                money(cents) {
                    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
                },
                formatRegion(value) {
                    return value.replaceAll('-', ' ').toUpperCase();
                },
                formatImage(value) {
                    return value.replaceAll('-', ' ').replace('ubuntu', 'Ubuntu').replace('debian', 'Debian');
                },
            };
        }
    </script>
</x-dashboard-layout>
