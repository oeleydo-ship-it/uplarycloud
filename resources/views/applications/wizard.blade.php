@php
    $isCustom = !$application;
    $environmentRows = collect($environment ?? [])->map(fn ($row) => [
        'key' => $row['key'] ?? '',
        'value' => $row['value'] ?? '',
        'description' => $row['description'] ?? '',
        'secret' => (bool) ($row['secret'] ?? false),
    ])->values();
@endphp
<x-dashboard-layout :title="$isCustom ? 'Deploy Custom Application' : 'Install '.$application->name">
<div class="deployment-wizard" x-data="deploymentWizard(@js($environmentRows), {{ $isCustom ? 'true' : 'false' }})" x-init="init()">
    <div class="page-heading"><div><p class="breadcrumb">Applications / {{ $isCustom ? 'Custom Docker' : 'Install '.$application->name }}</p><h1>{{ $isCustom ? 'Deploy Custom Application' : 'Install Application' }}</h1><p>{{ $isCustom ? 'Deploy any Docker image with secure configuration and persistent storage.' : 'Configure and deploy '.$application->name.' to a connected server.' }}</p></div><a href="{{ route('applications.index') }}" class="button button--secondary"><i data-lucide="x"></i></a></div>
    @if($application)<section class="card catalog-summary"><span class="catalog-icon" style="--accent:{{ $application->accent }}"><i data-lucide="{{ $application->icon }}"></i></span><div><h2>{{ $application->name }} <small>{{ $application->default_tag }}</small></h2><p>{{ $application->description }}</p></div><dl><div><dt>Category</dt><dd>{{ $application->category->name }}</dd></div><div><dt>Docker image</dt><dd>{{ $application->docker_image }}:{{ $application->default_tag }}</dd></div><div><dt>Required RAM</dt><dd>{{ $application->minimum_memory_mb }} MB</dd></div><div><dt>Required disk</dt><dd>{{ $application->minimum_disk_gb }} GB</dd></div></dl></section>@endif
    <div class="wizard-stepper deployment-stepper">@foreach([['Configure','Application settings'],['Select Server','Choose a server'],['Environment','Set variables'],['Review','Review configuration'],['Deploy','Install and start']] as $index=>[$label,$detail])<div class="wizard-step" :class="{'is-active':step==={{ $index+1 }},'is-complete':step>{{ $index+1 }}}"><span><b>{{ $index+1 }}</b><i data-lucide="check"></i></span><div><strong>{{ $label }}</strong><small>{{ $detail }}</small></div></div>@if($index<4)<i class="step-line"></i>@endif @endforeach</div>
    <form method="POST" action="{{ route('deployments.store') }}" @submit="submitting=true">@csrf<input type="hidden" name="application_id" value="{{ $application?->id }}"><input type="hidden" name="deployment_type" value="{{ $isCustom ? 'custom' : 'marketplace' }}">
        <section class="wizard-layout" x-show="step===1"><article class="card wizard-card"><div class="wizard-card-head"><span class="section-icon"><i data-lucide="blocks"></i></span><div><h2>Application details</h2><p>Basic runtime and resource configuration.</p></div></div><div class="form-grid form-grid--two wizard-fields">
            <label class="field"><span>Application name *</span><input name="name" x-model="form.name" required placeholder="My Application"></label><label class="field"><span>Domain (optional)</span><input name="domain" x-model="form.domain" placeholder="app.example.com"></label><label class="field field--wide"><span>Description</span><textarea name="description" rows="2" x-model="form.description" placeholder="What does this application do?"></textarea></label><label class="field"><span>Docker image *</span><input name="docker_image" x-model="form.image" required placeholder="ghcr.io/company/app"></label><label class="field"><span>Docker tag *</span><input name="docker_tag" x-model="form.tag" required></label><label class="field"><span>Container port</span><input type="number" name="container_port" x-model="form.port" min="1" max="65535"></label><label class="field"><span>Restart policy</span><select name="restart_policy" x-model="form.restart"><option value="unless-stopped">Unless stopped</option><option value="always">Always</option><option value="on-failure">On failure</option><option value="no">No</option></select></label><label class="field"><span>CPU limit</span><input type="number" step="0.1" name="cpu_limit" x-model="form.cpu" min="0.1"></label><label class="field"><span>Memory limit (MB)</span><input type="number" name="memory_limit_mb" x-model="form.memory" min="128"></label><label class="field"><span>Disk limit (GB)</span><input type="number" name="disk_limit_gb" x-model="form.disk" min="1"></label><label class="check field--wide"><input type="checkbox" name="auto_start" value="1" checked> Automatically start after deployment</label>
        </div></article><aside class="wizard-aside"><article class="card requirements-card"><span class="section-icon"><i data-lucide="shield-check"></i></span><h3>{{ $isCustom ? 'Custom Docker application' : 'Verified template' }}</h3><ul><li><i data-lucide="check"></i> Isolated Docker network</li><li><i data-lucide="check"></i> Encrypted environment variables</li><li><i data-lucide="check"></i> Persistent data volume</li><li><i data-lucide="check"></i> Automated health verification</li></ul></article><article class="card help-card"><i data-lucide="lightbulb"></i><div><h3>Production tip</h3><p>Use immutable image tags for predictable rollbacks and repeatable deployments.</p></div></article></aside></section>
        <section x-show="step===2" x-cloak><article class="card wizard-card"><div class="wizard-card-head"><span class="section-icon"><i data-lucide="server"></i></span><div><h2>Select server</h2><p>Offline or under-sized servers cannot be selected.</p></div></div><div class="server-choice-list">@foreach($servers as $server)@php($eligible=$server->status->value==='online' && $server->memory_mb >= ($application?->minimum_memory_mb ?? 128) && $server->disk_gb >= ($application?->minimum_disk_gb ?? 1))<label class="server-choice {{ $eligible ? '' : 'is-disabled' }}" :class="form.server=='{{ $server->id }}'&&'is-selected'"><input type="radio" name="server_id" value="{{ $server->id }}" x-model="form.server" @disabled(!$eligible)><span class="choice-dot"><i data-lucide="check"></i></span><span><strong>{{ $server->name }}</strong><small>{{ $server->operating_system }}</small></span><span><small>CPU</small><strong>{{ $server->cpu_cores }} vCPU</strong></span><span><small>RAM</small><strong>{{ round($server->memory_mb/1024,1) }} GB</strong></span><span><small>Disk</small><strong>{{ $server->disk_gb }} GB</strong></span><span><small>Location</small><strong>{{ $server->location ?: 'Not set' }}</strong></span><span><small>Status</small><em class="status status--{{ $eligible?'success':'warning' }}"><i></i>{{ $eligible?'Ready':ucfirst($server->status->value) }}</em></span></label>@endforeach</div></article></section>
        <section class="wizard-layout" x-show="step===3" x-cloak>
            <article class="card wizard-card">
                <div class="wizard-card-head">
                    <span class="section-icon"><i data-lucide="braces"></i></span>
                    <div>
                        <h2>Environment variables</h2>
                        <p>Required values are prefilled from the application template. Secrets are encrypted after saving.</p>
                    </div>
                </div>
                <div class="environment-editor">
                    <div class="environment-head">
                        <span>Variable name</span>
                        <span>Value</span>
                        <span>Description</span>
                        <span>Secret</span>
                        <span></span>
                    </div>
                    <template x-for="(variable, index) in environment" :key="index">
                        <div class="environment-row">
                            <input name="environment_keys[]" x-model="variable.key" placeholder="VARIABLE_NAME">
                            <div class="environment-value">
                                <input
                                    name="environment_values[]"
                                    x-model="variable.value"
                                    :type="variable.secret && !variable.revealed ? 'password' : 'text'"
                                    placeholder="Value"
                                >
                                <div class="environment-value-actions">
                                    <button type="button" class="env-icon-btn" title="Copy value" @click="copyValue(variable, index)" :disabled="!variable.value">
                                        <i data-lucide="copy" x-show="copied !== index"></i>
                                        <i data-lucide="check" x-show="copied === index" x-cloak></i>
                                    </button>
                                    <button type="button" class="env-icon-btn" title="Show or hide" x-show="variable.secret" @click="variable.revealed = !variable.revealed" x-cloak>
                                        <i data-lucide="eye" x-show="!variable.revealed"></i>
                                        <i data-lucide="eye-off" x-show="variable.revealed"></i>
                                    </button>
                                    <button type="button" class="env-icon-btn" title="Generate password" x-show="variable.secret" @click="regenerate(variable); $nextTick(() => window.renderIcons && window.renderIcons())" x-cloak>
                                        <i data-lucide="refresh-cw"></i>
                                    </button>
                                </div>
                            </div>
                            <input name="environment_descriptions[]" x-model="variable.description" placeholder="Description">
                            <label title="Mark as secret">
                                <input type="checkbox" name="environment_secrets[]" :value="index" x-model="variable.secret">
                                <i data-lucide="eye-off"></i>
                            </label>
                            <button type="button" @click="environment.splice(index, 1)" title="Remove variable">
                                <i data-lucide="trash-2"></i>
                            </button>
                        </div>
                    </template>
                    <button type="button" class="add-variable" @click="environment.push({key:'',value:'',description:'',secret:false,revealed:false}); $nextTick(() => window.renderIcons && window.renderIcons())">
                        <i data-lucide="plus"></i> Add environment variable
                    </button>
                </div>
            </article>
            <aside class="wizard-aside">
                <article class="card requirements-card">
                    <span class="section-icon"><i data-lucide="lock-keyhole"></i></span>
                    <h3>Secret handling</h3>
                    <ul>
                        <li><i data-lucide="check"></i> Values encrypted at rest</li>
                        <li><i data-lucide="check"></i> Secrets excluded from logs</li>
                        <li><i data-lucide="check"></i> Masked in application views</li>
                        <li><i data-lucide="check"></i> Copyable before deploy</li>
                    </ul>
                </article>
                @if($application?->template?->installation_notes)
                    <article class="card help-card">
                        <i data-lucide="info"></i>
                        <div>
                            <h3>Install notes</h3>
                            <p>{{ $application->template->installation_notes }}</p>
                        </div>
                    </article>
                @endif
            </aside>
        </section>
        <section class="wizard-layout" x-show="step===4" x-cloak>
            <article class="card wizard-card review-deployment">
                <div class="wizard-card-head"><span class="section-icon"><i data-lucide="clipboard-check"></i></span><div><h2>Review configuration</h2><p>Confirm everything before deployment.</p></div></div>
                <div class="review-block">
                    <div class="review-heading"><h3>Application</h3><button type="button" @click="step=1">Edit</button></div>
                    <dl>
                        <div><dt>Name</dt><dd x-text="form.name"></dd></div>
                        <div><dt>Image</dt><dd class="mono" x-text="form.image+':'+form.tag"></dd></div>
                        <div><dt>Domain</dt><dd x-text="form.domain||'IP & port'"></dd></div>
                        <div><dt>Port</dt><dd x-text="form.port||'Internal only'"></dd></div>
                    </dl>
                </div>
                <div class="review-block">
                    <div class="review-heading"><h3>Resources and security</h3><button type="button" @click="step=1">Edit</button></div>
                    <dl>
                        <div><dt>CPU</dt><dd x-text="form.cpu+' vCPU'"></dd></div>
                        <div><dt>Memory</dt><dd x-text="form.memory+' MB'"></dd></div>
                        <div><dt>Storage</dt><dd x-text="form.disk+' GB'"></dd></div>
                        <div><dt>Environment</dt><dd x-text="environment.length+' variables'"></dd></div>
                    </dl>
                </div>
                <div class="review-block" x-show="environment.length" x-cloak>
                    <div class="review-heading"><h3>Credentials to keep</h3><button type="button" @click="step=3">Edit</button></div>
                    <ul class="review-credentials">
                        <template x-for="(variable, index) in credentialPreview()" :key="'cred-'+index">
                            <li>
                                <strong x-text="variable.key"></strong>
                                <code x-text="variable.secret ? '••••••••' : variable.value"></code>
                                <button type="button" class="env-icon-btn" title="Copy" @click="copyValue(variable, 'review-'+index)" :disabled="!variable.value">
                                    <i data-lucide="copy"></i>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
                <label class="check"><input type="checkbox" name="backup_enabled" value="1"> Enable automatic backups for persistent data</label>
                <div class="precheck-list">
                    <span><i data-lucide="circle-check"></i> Server eligible</span>
                    <span><i data-lucide="circle-check"></i> Image validated</span>
                    <span><i data-lucide="circle-check"></i> Secrets protected</span>
                    <span><i data-lucide="circle-check"></i> Rollback ready</span>
                </div>
            </article>
            <aside class="wizard-aside">
                <article class="card requirements-card">
                    <span class="section-icon"><i data-lucide="rocket"></i></span>
                    <h3>Ready to deploy</h3>
                    <ul>
                        <li><i data-lucide="check"></i> Image will be pulled</li>
                        <li><i data-lucide="check"></i> Network and volume created</li>
                        <li><i data-lucide="check"></i> Container health checked</li>
                        <li><i data-lucide="check"></i> Release history recorded</li>
                    </ul>
                </article>
            </aside>
        </section>
        <div class="wizard-actions"><button type="button" class="button button--secondary" x-show="step>1" @click="step--"><i data-lucide="arrow-left"></i> Back</button><a href="{{ route('applications.index') }}" class="button button--secondary" x-show="step===1">Cancel</a><span></span><button type="button" class="button button--primary" x-show="step<4" @click="next()">Next <i data-lucide="arrow-right"></i></button><button type="submit" class="button button--primary" x-show="step===4" :disabled="submitting"><i data-lucide="rocket"></i><span x-text="submitting?'Queuing deployment...':'Deploy Application'"></span></button></div>
    </form>
</div>
<script>
function deploymentWizard(environment, isCustom) {
    return {
        step: 1,
        submitting: false,
        copied: null,
        environment: environment || [],
        form: {
            name: @js(old('name', $application?->name ?? '')),
            description: @js(old('description', $application?->description ?? '')),
            image: @js(old('docker_image', $application?->docker_image ?? '')),
            tag: @js(old('docker_tag', $application?->default_tag ?? 'latest')),
            port: @js(old('container_port', $application?->default_port ?? 3000)),
            domain: @js(old('domain', '')),
            cpu: @js(old('cpu_limit', $application?->minimum_cpu ?? .5)),
            memory: @js(old('memory_limit_mb', $application?->minimum_memory_mb ?? 512)),
            disk: @js(old('disk_limit_gb', $application?->minimum_disk_gb ?? 1)),
            restart: 'unless-stopped',
            server: @js((string) old('server_id', request('server', ''))),
        },
        init() {
            this.environment = (this.environment || []).map((variable) => ({
                key: variable.key || '',
                value: variable.value || '',
                description: variable.description || '',
                secret: !!variable.secret,
                revealed: false,
            }));
            this.$nextTick(() => window.renderIcons && window.renderIcons());
        },
        generatePassword(length = 24) {
            const alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*_-';
            const bytes = new Uint8Array(length);
            crypto.getRandomValues(bytes);
            return Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
        },
        regenerate(variable) {
            variable.value = this.generatePassword();
            variable.secret = true;
            variable.revealed = true;
        },
        async copyValue(variable, key) {
            if (!variable?.value) return;
            try {
                await navigator.clipboard.writeText(variable.value);
                this.copied = key;
                setTimeout(() => { if (this.copied === key) this.copied = null; }, 1600);
                this.$nextTick(() => window.renderIcons && window.renderIcons());
            } catch (e) {}
        },
        credentialPreview() {
            return this.environment.filter((variable) => {
                if (!variable.key || !variable.value) return false;
                const key = variable.key.toUpperCase();
                return variable.secret
                    || key.includes('USER')
                    || key.includes('PASSWORD')
                    || key.includes('TOKEN')
                    || key.includes('SECRET')
                    || key.includes('ADMIN');
            });
        },
        next() {
            if (this.step === 1 && (!this.form.name || !this.form.image || !this.form.tag)) {
                document.querySelector('[name=name]').reportValidity();
                return;
            }
            if (this.step === 2 && !this.form.server) {
                alert('Select an eligible server.');
                return;
            }
            this.step++;
            this.$nextTick(() => window.renderIcons && window.renderIcons());
        },
    };
}
</script>
<script>document.querySelector('[name=cpu_limit]').step='0.01'</script>
</x-dashboard-layout>
