@php
    $packs = $buildPacks->map(fn ($pack) => [
        'id' => $pack->id, 'name' => $pack->name, 'framework' => $pack->framework,
        'icon' => $pack->icon, 'accent' => $pack->accent,
        'versions' => $pack->runtime_versions, 'defaults' => $pack->defaults,
    ])->values();
@endphp
<x-dashboard-layout title="Deploy from Git">
<div class="git-wizard" x-data="gitDeploymentWizard(@js($packs))">
    <div class="page-heading"><div><p class="breadcrumb">Applications / Deploy from Git</p><h1>Deploy a web application</h1><p>Connect a repository, select a build pack, and ship an isolated production runtime.</p></div><a href="{{ route('applications.index') }}" class="button button--secondary"><i data-lucide="x"></i></a></div>

    @if($errors->any())<div class="form-alert"><i data-lucide="circle-alert"></i><div><strong>Review the highlighted settings</strong><p>{{ $errors->first() }}</p></div></div>@endif

    <div class="wizard-stepper git-stepper">@foreach([['Source','Repository & framework'],['Build','Runtime commands'],['Services','Infrastructure'],['Review','Deploy application']] as $index=>[$label,$detail])<div class="wizard-step" :class="{'is-active':step==={{ $index+1 }},'is-complete':step>{{ $index+1 }}}"><span><b>{{ $index+1 }}</b><i data-lucide="check"></i></span><div><strong>{{ $label }}</strong><small>{{ $detail }}</small></div></div>@if($index<3)<i class="step-line"></i>@endif @endforeach</div>

    <form method="POST" action="{{ route('applications.web.store') }}" @submit="submitting=true">@csrf
        <section x-show="step===1" class="git-source-layout">
            <article class="card git-source-card"><div class="wizard-card-head"><span class="section-icon"><i data-lucide="boxes"></i></span><div><h2>Choose your framework</h2><p>Build packs provide optimized runtime defaults you can customize.</p></div></div>
                <p class="field-error" x-show="sourceError" x-text="sourceError" x-cloak style="margin:12px 0 0"></p>
                <div class="build-pack-grid" x-show="packs.length"><template x-for="pack in packs" :key="pack.id"><button type="button" class="build-pack-card" :class="form.build_pack_id==pack.id&&'is-selected'" @click="selectPack(pack)"><span class="catalog-icon" :style="`--accent:${pack.accent}`"><i :data-lucide="pack.icon"></i></span><span><strong x-text="pack.name"></strong><small x-text="pack.framework==='laravel'?'PHP application':(pack.framework==='react'?'Static site':'Node application')"></small></span><i data-lucide="circle-check"></i></button></template></div>
                <p class="field-error" x-show="!packs.length" x-cloak style="margin:14px 0 0">No build packs are available. Run <code>php artisan db:seed --class=BuildPackSeeder</code> and reload.</p>
                <input type="hidden" name="build_pack_id" x-model="form.build_pack_id">
                <div class="form-grid form-grid--two git-fields"><label class="field"><span>Application name *</span><input name="name" x-model="form.name" required placeholder="Customer Portal"></label><label class="field"><span>Git provider *</span><select name="git_provider" x-model="form.git_provider"><option value="github">GitHub</option><option value="gitlab">GitLab</option><option value="bitbucket">Bitbucket</option></select></label><label class="field field--wide"><span>Repository URL *</span><div class="input-with-icon"><i data-lucide="git-branch"></i><input name="repository_url" x-model="form.repository_url" required placeholder="https://github.com/company/application.git"></div>@error('repository_url')<small class="field-error">{{ $message }}</small>@enderror</label><label class="field"><span>Branch *</span><input name="branch" x-model="form.branch" required placeholder="main"></label><label class="field"><span>Root directory *</span><input name="root_directory" x-model="form.root_directory" required placeholder="/"></label><label class="field field--wide"><span>Deploy key <em>Optional for private repositories</em></span><textarea name="deploy_key" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea><small>Encrypted at rest and used only while cloning.</small></label></div>
            </article>
            <aside class="git-wizard-aside"><article class="card git-help-card"><span class="section-icon"><i data-lucide="scan-search"></i></span><h3>Automatic detection</h3><p>Uplary detects the selected framework, locks the runtime, installs dependencies, and creates a production Docker image.</p><ul><li><i data-lucide="check"></i> GitHub, GitLab & Bitbucket</li><li><i data-lucide="check"></i> Private repository deploy keys</li><li><i data-lucide="check"></i> Immutable release history</li></ul></article></aside>
        </section>

        <section x-show="step===2" x-cloak class="git-source-layout">
            <article class="card git-source-card git-build-card">
                <div class="wizard-card-head">
                    <span class="section-icon"><i data-lucide="terminal-square"></i></span>
                    <div>
                        <h2>Build configuration</h2>
                        <p>Commands are validated before they are used in the isolated image build.</p>
                    </div>
                </div>
                <div class="runtime-banner">
                    <span class="catalog-icon" :style="`--accent:${selected.accent}`"><i :data-lucide="selected.icon"></i></span>
                    <div>
                        <strong x-text="selected.name+' build pack'"></strong>
                        <small>Production-optimized Docker runtime</small>
                    </div>
                    <span class="verified-pill"><i data-lucide="shield-check"></i> Validated</span>
                </div>
                <div class="form-grid form-grid--two git-fields">
                    <label class="field">
                        <span>Runtime version *</span>
                        <select name="runtime_version" x-model="form.runtime_version">
                            <template x-for="version in selected.versions"><option :value="version" x-text="version"></option></template>
                        </select>
                    </label>
                    <label class="field">
                        <span>Package manager</span>
                        <select name="package_manager" x-model="form.package_manager">
                            <option value="composer" x-show="selected.framework==='laravel'">Composer</option>
                            <option value="npm">npm</option>
                            <option value="pnpm">pnpm</option>
                            <option value="yarn">Yarn</option>
                            <option value="bun">Bun</option>
                        </select>
                    </label>
                    <label class="field field--wide">
                        <span>Install command</span>
                        <div class="command-input"><code>$</code><input name="install_command" x-model="form.install_command"></div>
                    </label>
                    <label class="field field--wide">
                        <span>Build command</span>
                        <div class="command-input"><code>$</code><input name="build_command" x-model="form.build_command"></div>
                    </label>
                    <label class="field field--wide" x-show="selected.framework!=='react'">
                        <span>Start command</span>
                        <div class="command-input"><code>$</code><input name="start_command" x-model="form.start_command"></div>
                    </label>
                    <label class="field" x-show="selected.framework==='react'">
                        <span>Output directory</span>
                        <input name="output_directory" x-model="form.output_directory" placeholder="dist">
                    </label>
                    <label class="field">
                        <span>Application port *</span>
                        <input type="number" name="container_port" x-model="form.container_port" min="1" max="65535">
                    </label>
                </div>
            </article>
            <aside class="git-wizard-aside">
                <article class="card git-help-card git-pipeline-card">
                    <span class="section-icon"><i data-lucide="package-check"></i></span>
                    <h3>Build pipeline</h3>
                    <p>Each deploy runs these stages in an isolated builder before the release is published.</p>
                    <ol class="git-pipeline build-pipeline-list">
                        <li><b>1</b><span><strong>Clone exact branch</strong><small>Fetch the selected Git ref into a clean workspace</small></span></li>
                        <li><b>2</b><span><strong>Install dependencies</strong><small>Run the install command with the chosen package manager</small></span></li>
                        <li><b>3</b><span><strong>Build application</strong><small>Compile assets and prepare the production artifact</small></span></li>
                        <li><b>4</b><span><strong>Package Docker image</strong><small>Seal an immutable image for rollout and rollback</small></span></li>
                    </ol>
                    <p class="build-pipeline-note">Commands are allowlisted and executed only inside the builder sandbox.</p>
                </article>
            </aside>
        </section>

        <section x-show="step===3" x-cloak class="git-source-layout"><article class="card git-source-card"><div class="wizard-card-head"><span class="section-icon"><i data-lucide="waypoints"></i></span><div><h2>Services and environment</h2><p>Place supporting services on the same private Docker network.</p></div></div><div class="form-grid form-grid--two git-fields"><label class="field"><span>Target server *</span><select name="server_id" x-model="form.server_id" required><option value="">Select an online server</option>@foreach($servers as $server)<option value="{{ $server->id }}" @disabled($server->status->value!=='online')>{{ $server->name }} · {{ round($server->memory_mb/1024,1) }} GB · {{ ucfirst($server->status->value) }}</option>@endforeach</select></label><label class="field"><span>Domain</span><input name="domain" x-model="form.domain" placeholder="app.example.com"></label><label class="field"><span>CPU limit</span><input type="number" step="0.1" min="0.1" name="cpu_limit" x-model="form.cpu_limit"></label><label class="field"><span>Memory (MB)</span><input type="number" min="128" name="memory_limit_mb" x-model="form.memory_limit_mb"></label><label class="field"><span>Storage (GB)</span><input type="number" min="1" name="disk_limit_gb" x-model="form.disk_limit_gb"></label><label class="field" x-show="selected.framework==='laravel'"><span>Database</span><select name="database_engine" x-model="form.database_engine"><option value="">No database</option><option value="mysql">MariaDB 11</option><option value="postgresql">PostgreSQL 16</option></select></label></div><div class="service-toggle-grid" x-show="selected.framework==='laravel'"><label><input type="checkbox" name="enable_redis" value="1" x-model="form.enable_redis"><span><i data-lucide="database-zap"></i><strong>Redis</strong><small>Cache, sessions, and queues</small></span></label><label><input type="checkbox" name="enable_queue" value="1" x-model="form.enable_queue" :disabled="form.enable_horizon"><span><i data-lucide="list-start"></i><strong>Queue worker</strong><small>Background jobs via Redis</small></span></label><label><input type="checkbox" name="enable_horizon" value="1" x-model="form.enable_horizon" @change="if (form.enable_horizon) { form.enable_redis = true; form.enable_queue = false }"><span><i data-lucide="gauge"></i><strong>Laravel Horizon</strong><small>Redis queue supervisor · requires laravel/horizon</small></span></label><label><input type="checkbox" name="enable_reverb" value="1" x-model="form.enable_reverb"><span><i data-lucide="radio"></i><strong>Reverb</strong><small>Realtime websockets</small></span></label><label><input type="checkbox" name="enable_scheduler" value="1" x-model="form.enable_scheduler"><span><i data-lucide="calendar-clock"></i><strong>Scheduler</strong><small>Scheduled tasks</small></span></label></div><div class="environment-editor git-environment"><div class="environment-title"><div><h3>Environment variables</h3><p>Secret values are encrypted and masked after deployment.</p></div><button type="button" class="add-variable" @click="environment.push({key:'',value:'',secret:false})"><i data-lucide="plus"></i> Add variable</button></div><template x-for="(variable,index) in environment" :key="index"><div class="git-environment-row"><input name="environment_keys[]" x-model="variable.key" placeholder="VARIABLE_NAME"><input name="environment_values[]" x-model="variable.value" :type="variable.secret?'password':'text'" placeholder="Value"><label title="Secret"><input type="checkbox" name="environment_secrets[]" :value="String(index)" x-model="variable.secret"><i data-lucide="eye-off"></i></label><button type="button" @click="environment.splice(index,1)"><i data-lucide="trash-2"></i></button></div></template></div><label class="auto-deploy-toggle"><input type="checkbox" name="auto_deploy" value="1" x-model="form.auto_deploy"><span><i data-lucide="webhook"></i><strong>Automatic deployments</strong><small>Generate a signed webhook and redeploy on every push.</small></span><i data-lucide="check"></i></label></article><aside class="git-wizard-aside"><article class="card git-help-card"><span class="section-icon"><i data-lucide="shield"></i></span><h3>Private by default</h3><p>Database, Redis, queues, and the application communicate over an isolated Docker network.</p><ul><li><i data-lucide="check"></i> Encrypted configuration</li><li><i data-lucide="check"></i> Independent sidecars</li><li><i data-lucide="check"></i> Automatic restart policies</li></ul></article></aside></section>

        <section x-show="step===4" x-cloak class="git-source-layout"><article class="card git-source-card git-review"><div class="wizard-card-head"><span class="section-icon"><i data-lucide="clipboard-check"></i></span><div><h2>Review and deploy</h2><p>Confirm the source, runtime, and infrastructure configuration.</p></div></div><div class="review-hero"><span class="catalog-icon" :style="`--accent:${selected.accent}`"><i :data-lucide="selected.icon"></i></span><div><h3 x-text="form.name||'Untitled application'"></h3><p><span x-text="selected.name"></span> · <span x-text="form.runtime_version"></span> · <span x-text="form.branch"></span></p></div><span class="status status--success"><i></i> Ready</span></div><div class="git-review-grid"><div><h3>Source</h3><dl><div><dt>Provider</dt><dd x-text="form.git_provider"></dd></div><div><dt>Repository</dt><dd class="mono truncate" x-text="form.repository_url"></dd></div><div><dt>Branch</dt><dd x-text="form.branch"></dd></div><div><dt>Root</dt><dd x-text="form.root_directory"></dd></div></dl><button type="button" @click="step=1">Edit source</button></div><div><h3>Build</h3><dl><div><dt>Runtime</dt><dd x-text="selected.name+' '+form.runtime_version"></dd></div><div><dt>Install</dt><dd class="mono" x-text="form.install_command"></dd></div><div><dt>Build</dt><dd class="mono" x-text="form.build_command||'Not required'"></dd></div><div><dt>Port</dt><dd x-text="form.container_port"></dd></div></dl><button type="button" @click="step=2">Edit build</button></div><div><h3>Infrastructure</h3><dl><div><dt>Domain</dt><dd x-text="form.domain||'IP & port'"></dd></div><div><dt>Resources</dt><dd x-text="form.cpu_limit+' vCPU · '+form.memory_limit_mb+' MB'"></dd></div><div><dt>Services</dt><dd x-text="serviceSummary()"></dd></div><div><dt>Deployments</dt><dd x-text="form.auto_deploy?'On every push':'Manual'"></dd></div></dl><button type="button" @click="step=3">Edit services</button></div></div><div class="precheck-list"><span><i data-lucide="circle-check"></i> Repository validated</span><span><i data-lucide="circle-check"></i> Commands allowlisted</span><span><i data-lucide="circle-check"></i> Secrets protected</span><span><i data-lucide="circle-check"></i> Rollback enabled</span></div></article><aside class="git-wizard-aside"><article class="card git-help-card deploy-summary"><span class="section-icon"><i data-lucide="rocket"></i></span><h3>Ready to ship</h3><p>The build runs asynchronously. Live progress and logs will appear immediately.</p><div><strong>10</strong><small>automated stages</small></div></article></aside></section>

        <div class="wizard-actions"><button type="button" class="button button--secondary" x-show="step>1" @click="step--"><i data-lucide="arrow-left"></i> Back</button><a href="{{ route('applications.index') }}" class="button button--secondary" x-show="step===1">Cancel</a><span></span><button type="button" class="button button--primary" x-show="step<4" @click="next()">Continue <i data-lucide="arrow-right"></i></button><button type="submit" class="button button--primary" x-show="step===4" :disabled="submitting"><i data-lucide="rocket"></i><span x-text="submitting?'Queuing build...':'Deploy from Git'"></span></button></div>
    </form>
</div>
<script>
function gitDeploymentWizard(packs) {
    const fallback = {id: '', name: 'Build pack', framework: 'node', icon: 'code-2', accent: '#6c4cf5', versions: ['22'], defaults: {}};
    const first = packs[0] || fallback;

    return {
        step: 1,
        submitting: false,
        sourceError: '',
        packs,
        selected: first,
        environment: [],
        form: {
            build_pack_id: @js(old('build_pack_id')) || first.id,
            name: @js(old('name', '')),
            git_provider: @js(old('git_provider', 'github')),
            repository_url: @js(old('repository_url', '')),
            branch: @js(old('branch', 'main')),
            root_directory: @js(old('root_directory', '/')),
            runtime_version: @js(old('runtime_version')) || (first.versions || ['22'])[0],
            package_manager: '',
            install_command: '',
            build_command: '',
            start_command: '',
            output_directory: '',
            container_port: 3000,
            server_id: @js(old('server_id', '')),
            domain: @js(old('domain', '')),
            cpu_limit: .5,
            memory_limit_mb: 512,
            disk_limit_gb: 2,
            database_engine: 'mysql',
            enable_redis: false,
            enable_queue: false,
            enable_horizon: false,
            enable_reverb: false,
            enable_scheduler: false,
            auto_deploy: false,
        },
        init() {
            if (!this.packs.length) {
                this.sourceError = 'No build packs are available. Seed build packs, then reload this page.';
                return;
            }
            const chosen = this.packs.find((p) => String(p.id) === String(this.form.build_pack_id)) || first;
            this.selectPack(chosen);
        },
        selectPack(pack) {
            if (!pack || !pack.id) return;
            this.sourceError = '';
            this.selected = pack;
            this.form.build_pack_id = pack.id;
            this.form.runtime_version = (pack.versions || ['22'])[0];
            Object.entries(pack.defaults || {}).forEach(([key, value]) => {
                if (value !== null) this.form[key] = value;
            });
            this.$nextTick(() => window.renderIcons && window.renderIcons());
        },
        reportField(name) {
            const field = document.querySelector(`[name="${name}"]`);
            if (field && typeof field.reportValidity === 'function') {
                field.focus();
                field.reportValidity();
            }
        },
        next() {
            if (this.step === 1) {
                if (!this.packs.length || !this.form.build_pack_id) {
                    this.sourceError = this.packs.length
                        ? 'Select a framework build pack to continue.'
                        : 'No build packs are available. Seed build packs, then reload this page.';
                    return;
                }
                const required = ['name', 'repository_url', 'branch', 'root_directory'];
                for (const name of required) {
                    const value = String(this.form[name] ?? '').trim();
                    if (!value) {
                        this.reportField(name);
                        return;
                    }
                }
                this.sourceError = '';
            }

            if (this.step === 3 && !this.form.server_id) {
                this.reportField('server_id');
                return;
            }

            this.step++;
            this.$nextTick(() => window.renderIcons && window.renderIcons());
        },
        serviceSummary() {
            const s = [];
            if (this.form.database_engine) s.push(this.form.database_engine);
            if (this.form.enable_redis || this.form.enable_queue || this.form.enable_reverb || this.form.enable_horizon) s.push('Redis');
            if (this.form.enable_horizon) s.push('Horizon');
            else if (this.form.enable_queue) s.push('Queue');
            if (this.form.enable_reverb) s.push('Reverb');
            if (this.form.enable_scheduler) s.push('Scheduler');
            return s.length ? s.join(', ') : 'Application only';
        },
    };
}
</script>
</x-dashboard-layout>
