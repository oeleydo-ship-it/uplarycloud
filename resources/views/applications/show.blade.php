<x-dashboard-layout :title="$deployment->name">
    <div
        class="deployment-show-page"
        x-data="deploymentProgress(@js(route('deployments.status', $deployment)), @js($deployment->status->value), {{ $deployment->progress }})"
        x-init="start()"
    >
        <div class="page-heading deployment-reference-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('applications.installed') }}">Applications</a>
                    <i data-lucide="chevron-right"></i>
                    {{ $deployment->name }}
                </p>
                <h1 x-text="headingTitle()">{{ $deployment->status->value === 'running' ? 'Application deployed' : ($deployment->status->value === 'failed' ? 'Deployment failed' : 'Deploy Application') }}</h1>
                <p x-text="headingSubtitle()">{{ $deployment->status->value === 'running' ? 'Your application is running and ready to use.' : ($deployment->status->value === 'failed' ? 'Installation did not finish on the server. Check the logs below.' : 'Deployment is processed securely in the background.') }}</p>
            </div>
            <div class="heading-actions">
                <a href="{{ route('applications.installed') }}" class="button button--secondary"><i data-lucide="layout-grid"></i> Applications</a>
                @if($deployment->domain)
                    <a href="https://{{ $deployment->domain }}" target="_blank" class="button button--primary" x-show="status === 'running'" x-cloak><i data-lucide="external-link"></i> Open Application</a>
                @endif
                @if($deployment->server)
                    @can('operate', $deployment->server)
                        @if(in_array($deployment->status->value, ['running', 'failed', 'queued'], true))
                            @if(in_array($deployment->status->value, ['running', 'failed'], true))
                                <form method="POST" action="{{ route('deployments.verify', $deployment) }}">
                                    @csrf
                                    <button type="submit" class="button button--secondary"><i data-lucide="shield-check"></i> Verify on server</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('deployments.redeploy', $deployment) }}">
                                @csrf
                                <button type="submit" class="button button--secondary">
                                    <i data-lucide="refresh-cw"></i>
                                    {{ $deployment->status->value === 'queued' ? 'Retry queue' : 'Redeploy' }}
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('deployments.destroy', $deployment) }}" onsubmit="return confirm(@js('Remove '.$deployment->name.' from Uplary Cloud? This removes the application from the control plane. Remote containers and volumes on the server will not be deleted.'))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="button button--danger"><i data-lucide="trash-2"></i> Delete application</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>

        <section class="card deployment-success-banner" x-show="status === 'running'" x-cloak>
            <span class="deployment-success-icon"><i data-lucide="check"></i></span>
            <div>
                <h2>Successfully deployed</h2>
                <p>{{ $deployment->name }} is installed and running on {{ $deployment->server?->name ?? 'the selected server' }}.</p>
                <div class="deployment-success-actions">
                    @if($deployment->domain)
                        <a href="https://{{ $deployment->domain }}" target="_blank" class="button button--primary"><i data-lucide="external-link"></i> Open application</a>
                    @elseif($deployment->server?->ip_address && $deployment->container_port)
                        <a href="http://{{ $deployment->server->ip_address }}:{{ $deployment->container_port }}" target="_blank" class="button button--primary"><i data-lucide="external-link"></i> Open application</a>
                    @else
                        <a href="#overview" class="button button--primary">Manage application</a>
                    @endif
                    <a href="#credentials" class="button button--secondary">View credentials</a>
                    <a href="#logs" class="button button--secondary">View logs</a>
                </div>
            </div>
        </section>

        <div class="deployment-reference-layout">
            <main class="deployment-reference-main">
                <article class="card deployment-reference-progress">
                    <div class="deployment-reference-summary">
                        <span class="deployment-reference-icon"><i data-lucide="rocket"></i></span>
                        <div>
                            <h2>Deployment progress</h2>
                            <p>{{ $deployment->name }} on {{ $deployment->server?->name ?? 'unavailable server' }}</p>
                        </div>
                        <strong x-text="progress + '%'">{{ $deployment->progress }}%</strong>
                    </div>
                    <div class="deployment-reference-track"><span :style="`width:${progress}%`"></span></div>
                    <div class="deployment-reference-stages">
                        <template x-for="item in steps" :key="item.key">
                            <div class="deployment-reference-stage" :class="'is-' + item.status">
                                <span>
                                    <i x-show="item.status === 'completed'" data-lucide="check"></i>
                                    <i x-show="item.status === 'running'" data-lucide="loader-circle"></i>
                                    <i x-show="item.status === 'pending'" data-lucide="circle"></i>
                                    <i x-show="item.status === 'failed'" data-lucide="x"></i>
                                </span>
                                <strong x-text="item.name"></strong>
                                <small x-text="item.status.replace('_', ' ')"></small>
                            </div>
                        </template>
                    </div>
                    <div class="deployment-reference-error" x-show="error" x-cloak>
                        <i data-lucide="triangle-alert"></i>
                        <span x-text="error"></span>
                    </div>
                </article>

                <article id="logs" class="card deployment-reference-logs">
                    <div class="live-log-head">
                        <span><i></i> Deployment logs</span>
                        <small>Live updates</small>
                    </div>
                    <div class="terminal-lines deployment-reference-terminal">
                        <template x-for="(log, index) in logs" :key="index">
                            <p>
                                <span x-text="log.time"></span>
                                <b :class="'log-' + log.level" x-text="'[' + log.level.toUpperCase() + ']'"></b>
                                <em x-text="log.message"></em>
                            </p>
                        </template>
                    </div>
                </article>

                <article id="overview" class="card deployment-reference-overview">
                    <div class="card-head">
                        <div>
                            <h2>Deployment overview</h2>
                            <p>Runtime configuration and protected settings.</p>
                        </div>
                    </div>
                    <dl class="deployment-reference-facts">
                        <div><dt>Application</dt><dd>{{ $deployment->name }}</dd></div>
                        <div><dt>Server</dt><dd>{{ $deployment->server?->name ?? 'Server removed' }}</dd></div>
                        @if($deployment->deployment_type === 'git')
                            <div><dt>Framework</dt><dd>{{ $deployment->buildPack?->name }} {{ $deployment->runtime_version }}</dd></div>
                            <div><dt>Repository</dt><dd class="mono">{{ $deployment->repository_url }}</dd></div>
                            <div><dt>Branch / commit</dt><dd class="mono">{{ $deployment->branch }} · {{ $deployment->commit_hash ? substr($deployment->commit_hash, 0, 8) : 'pending' }}</dd></div>
                            @if($deployment->enable_redis || $deployment->enable_queue || $deployment->enable_reverb || $deployment->enable_horizon || $deployment->enable_scheduler)
                                <div><dt>Sidecars</dt><dd>{{ collect(['Redis' => $deployment->enable_redis, 'Queue' => $deployment->enable_queue && ! $deployment->enable_horizon, 'Horizon' => $deployment->enable_horizon, 'Reverb' => $deployment->enable_reverb, 'Scheduler' => $deployment->enable_scheduler])->filter()->keys()->join(', ') ?: 'None' }}</dd></div>
                            @endif
                        @endif
                        <div><dt>Docker image</dt><dd class="mono">{{ $deployment->docker_image }}:{{ $deployment->docker_tag }}</dd></div>
                        <div><dt>Container port</dt><dd>{{ $deployment->container_port ?: 'Internal' }}</dd></div>
                        <div><dt>CPU limit</dt><dd>{{ $deployment->cpu_limit ?: 'Unlimited' }}</dd></div>
                        <div><dt>Memory limit</dt><dd>{{ $deployment->memory_limit_mb ? $deployment->memory_limit_mb.' MB' : 'Unlimited' }}</dd></div>
                        <div><dt>Restart policy</dt><dd>{{ $deployment->restart_policy }}</dd></div>
                        <div><dt>Backups</dt><dd>{{ $deployment->backup_enabled ? 'Enabled' : 'Disabled' }}</dd></div>
                    </dl>
                    <div id="credentials" class="deployment-reference-env" x-data="deploymentCredentials(@js(
                        $deployment->environmentVariables->map(fn ($variable) => [
                            'key' => $variable->key,
                            'masked' => $variable->maskedValue(),
                            'secret' => $variable->secret,
                            'value' => ($deployment->server && auth()->user()?->can('operate', $deployment->server)) ? (string) $variable->value : null,
                            'description' => $variable->description,
                        ])->values()
                    ))">
                        <div class="deployment-reference-env-head">
                            <h3>Environment & credentials</h3>
                            @if($deployment->server)
                                @can('operate', $deployment->server)
                                    <small>Operators can reveal and copy secrets.</small>
                                @else
                                    <small>Secrets stay masked for your role.</small>
                                @endcan
                            @endif
                        </div>
                        @if($deployment->domain || ($deployment->server?->ip_address && $deployment->container_port))
                            <div class="deployment-reference-env-row deployment-login-url">
                                <strong>Application URL</strong>
                                <div class="deployment-env-value">
                                    @php
                                        $loginUrl = $deployment->domain
                                            ? 'https://'.$deployment->domain
                                            : 'http://'.$deployment->server->ip_address.':'.$deployment->container_port;
                                    @endphp
                                    <code>{{ $loginUrl }}</code>
                                    <button type="button" class="env-icon-btn" title="Copy URL" @click="copyText(@js($loginUrl), 'url')">
                                        <i data-lucide="copy" x-show="copied !== 'url'"></i>
                                        <i data-lucide="check" x-show="copied === 'url'" x-cloak></i>
                                    </button>
                                </div>
                            </div>
                        @endif
                        <template x-for="(variable, index) in variables" :key="variable.key + '-' + index">
                            <div class="deployment-reference-env-row">
                                <div>
                                    <strong x-text="variable.key"></strong>
                                    <small x-show="variable.description" x-text="variable.description" x-cloak></small>
                                </div>
                                <div class="deployment-env-value">
                                    <code x-text="displayValue(variable)"></code>
                                    <button type="button" class="env-icon-btn" title="Copy" x-show="variable.value" @click="copyText(variable.value, index)" x-cloak>
                                        <i data-lucide="copy" x-show="copied !== index"></i>
                                        <i data-lucide="check" x-show="copied === index"></i>
                                    </button>
                                    <button type="button" class="env-icon-btn" title="Reveal" x-show="variable.secret && variable.value" @click="variable.revealed = !variable.revealed" x-cloak>
                                        <i data-lucide="eye" x-show="!variable.revealed"></i>
                                        <i data-lucide="eye-off" x-show="variable.revealed"></i>
                                    </button>
                                </div>
                            </div>
                        </template>
                        <p class="deployment-reference-empty" x-show="!variables.length" x-cloak>No environment variables configured.</p>
                    </div>
                </article>
            </main>

            <aside class="deployment-reference-aside">
                <article class="card deployment-reference-app">
                    <div class="deployment-reference-app-head">
                        <x-application-icon :application="$deployment->application" size="lg" />
                        <div>
                            <h2>{{ $deployment->name }}</h2>
                            <p>{{ $deployment->application?->description ?? $deployment->description ?? 'Deployed application workload.' }}</p>
                        </div>
                    </div>
                    <dl class="deployment-reference-meta">
                        <div><dt>Server</dt><dd>{{ $deployment->server?->name ?? 'Server removed' }}</dd></div>
                        <div><dt>Location</dt><dd>{{ $deployment->server?->location ?: '—' }}</dd></div>
                        <div>
                            <dt>Status</dt>
                            <dd>
                                <span
                                    class="deployment-reference-status"
                                    :class="status === 'running' ? 'is-success' : (status === 'failed' ? 'is-danger' : 'is-running')"
                                >
                                    <i></i>
                                    <span x-text="status.replace('_', ' ')"></span>
                                </span>
                            </dd>
                        </div>
                        <div><dt>Started</dt><dd>{{ $deployment->started_at?->diffForHumans() ?? 'Queued' }}</dd></div>
                    </dl>
                </article>

                @if($deployment->deployment_type === 'git' && $deployment->auto_deploy)
                    <article class="card deployment-reference-webhook">
                        <div class="card-head">
                            <div>
                                <h2>Git auto deploy</h2>
                                <p>Trigger a signed deployment on push.</p>
                            </div>
                            <span class="deployment-reference-status is-success"><i></i> Active</span>
                        </div>
                        <div class="deployment-reference-webhook-body">
                            <label>Webhook URL</label>
                            <code>{{ route('hooks.git', $deployment) }}</code>
                            @if(session('webhook_secret'))
                                <label>Webhook secret · shown once</label>
                                <code>{{ session('webhook_secret') }}</code>
                            @else
                                <p>The signing secret is encrypted and cannot be displayed again.</p>
                            @endif
                        </div>
                    </article>
                @endif

                <article class="card deployment-reference-releases">
                    <div class="card-head">
                        <div>
                            <h2>Release history</h2>
                            <p>Rollback without restoring secrets.</p>
                        </div>
                    </div>
                    @forelse($deployment->releases as $release)
                        <div class="deployment-reference-release">
                            <span>
                                <strong>{{ $release->version }}</strong>
                                <small>{{ $release->image_tag }} · {{ $release->deployed_at->diffForHumans() }}</small>
                            </span>
                            @if($release->is_current)
                                <em>Current</em>
                            @else
                                <form method="POST" action="{{ route('deployments.rollback', [$deployment, $release]) }}">
                                    @csrf
                                    <button type="submit" onclick="return confirm('Rollback to this release?')">Rollback</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="deployment-reference-empty">Release history appears after the first successful deployment.</p>
                    @endforelse
                </article>
            </aside>
        </div>
    </div>

    <script>
        function deploymentCredentials(variables) {
            return {
                copied: null,
                variables: (variables || []).map((variable) => ({
                    ...variable,
                    revealed: false,
                })),
                displayValue(variable) {
                    if (!variable.secret) return variable.value ?? variable.masked;
                    if (!variable.value) return variable.masked;
                    return variable.revealed ? variable.value : variable.masked;
                },
                async copyText(value, key) {
                    if (!value) return;
                    try {
                        await navigator.clipboard.writeText(value);
                        this.copied = key;
                        setTimeout(() => { if (this.copied === key) this.copied = null; }, 1600);
                        this.$nextTick(() => window.renderIcons && window.renderIcons());
                    } catch (e) {}
                },
            };
        }

        function deploymentProgress(url, initialStatus, initialProgress) {
            return {
                status: initialStatus,
                progress: initialProgress,
                error: null,
                steps: @js($deployment->steps->map(fn ($s) => ['key' => $s->key, 'name' => $s->name, 'status' => $s->status])),
                logs: @js($deployment->logs->map(fn ($l) => ['level' => $l->level, 'message' => $l->message, 'time' => $l->occurred_at->format('H:i:s')])),
                timer: null,
                headingTitle() {
                    if (this.status === 'running') return 'Application deployed';
                    if (this.status === 'failed') return 'Deployment failed';
                    return 'Deploy Application';
                },
                headingSubtitle() {
                    if (this.status === 'running') return 'Your application is running and ready to use.';
                    if (this.status === 'failed') return 'Installation did not finish on the server. Check the logs below.';
                    return 'Waiting until install finishes on the server before showing success.';
                },
                start() {
                    if (['queued', 'deploying', 'rolling_back'].includes(this.status)) {
                        this.poll();
                        this.timer = setInterval(() => this.poll(), 1400);
                        if (window.Echo) {
                            window.Echo.private('tenants.{{ $deployment->tenant_id }}.deployments')
                                .listen('.deployment.progressed', (event) => {
                                    if (event.deploymentUuid === @js($deployment->uuid)) {
                                        this.poll();
                                    }
                                });
                        }
                    }
                },
                async poll() {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    const data = await response.json();
                    this.status = data.status;
                    this.progress = data.progress;
                    this.error = data.error;
                    this.steps = data.steps;
                    this.logs = data.logs;
                    this.$nextTick(() => window.renderIcons && window.renderIcons());
                    if (['running', 'failed', 'stopped'].includes(this.status)) {
                        clearInterval(this.timer);
                        setTimeout(() => location.reload(), 700);
                    }
                },
            };
        }
    </script>
</x-dashboard-layout>
