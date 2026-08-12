<x-dashboard-layout title="Provisioning {{ $server->name }}">
    @php
        $initialStatus = $server->status->value;
        $canStart = $needsAttention ?? false;
        $actionLabel = match (true) {
            $server->status === \App\Enums\ServerStatus::Failed => 'Retry provisioning',
            $server->status === \App\Enums\ServerStatus::Online => 'Re-provision server',
            default => 'Start provisioning',
        };
        $panelTitle = match (true) {
            $server->status === \App\Enums\ServerStatus::Failed => 'Provisioning needs attention',
            $server->status === \App\Enums\ServerStatus::Online => 'Re-provision required',
            $server->status === \App\Enums\ServerStatus::Pending => 'Provisioning has not started',
            in_array($server->status, [\App\Enums\ServerStatus::Provisioning, \App\Enums\ServerStatus::Testing], true) => 'Provisioning in progress',
            default => 'Provisioning status',
        };
        $panelMessage = $attentionMessage
            ?: match (true) {
                $server->status === \App\Enums\ServerStatus::Pending => 'Queue the installation job to begin Docker and platform setup.',
                in_array($server->status, [\App\Enums\ServerStatus::Provisioning, \App\Enums\ServerStatus::Testing], true) => 'Steps are running on the server. Progress updates automatically.',
                $server->status === \App\Enums\ServerStatus::Online => 'Server is online. Re-run only if remote services need repair.',
                default => 'Watch the checklist and live log for progress.',
            };
        $panelTone = match (true) {
            $server->status === \App\Enums\ServerStatus::Failed => 'failed',
            $server->status === \App\Enums\ServerStatus::Pending => 'pending',
            in_array($server->status, [\App\Enums\ServerStatus::Provisioning, \App\Enums\ServerStatus::Testing], true) => 'running',
            $server->status === \App\Enums\ServerStatus::Online => 'complete',
            default => 'idle',
        };
        $panelIcon = match ($panelTone) {
            'failed' => 'triangle-alert',
            'pending' => 'play',
            'running' => 'loader-circle',
            'complete' => 'check-circle-2',
            default => 'info',
        };
    @endphp

    <div
        class="provision-page"
        x-data="provisioning({
            url: @js(route('servers.provisioning.status', $server)),
            status: @js($initialStatus),
            canStart: @js($canStart),
            actionLabel: @js($actionLabel),
            panelTitle: @js($panelTitle),
            panelMessage: @js($panelMessage),
            panelTone: @js($panelTone),
            panelIcon: @js($panelIcon),
            retryUrl: @js(route('servers.provisioning.retry', $server)),
            csrf: @js(csrf_token()),
        })"
        x-init="start()"
    >
        <div class="page-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('servers.index') }}">Servers</a>
                    <span>/</span>
                    <a href="{{ route('servers.details', $server) }}">{{ $server->name }}</a>
                    <span>/</span>
                    Provision
                </p>
                <h1>Provisioning {{ $server->name }}</h1>
                <p>Docker and platform services are being installed securely.</p>
            </div>
            <span class="status status--{{ $server->status->tone() }}" :class="'status--' + statusTone">
                <i></i>
                <span x-text="statusLabel">{{ $server->status->label() }}</span>
            </span>
        </div>

        <div class="provision-layout">
            <article class="card provision-card">
                <div class="provision-summary">
                    <span class="provision-server-icon"><i data-lucide="server-cog"></i></span>
                    <div>
                        <h2>Preparing your server</h2>
                        <p>This normally takes 2–5 minutes. You may safely leave this page.</p>
                    </div>
                    <strong class="provision-percent" x-text="progress + '%'">0%</strong>
                </div>
                <div class="provision-progress" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                    <span :style="`width:${progress}%`"></span>
                </div>
                <div class="provision-progress-meta">
                    <span x-text="completedCount + ' of ' + steps.length + ' steps complete'">0 of 0 steps complete</span>
                    <span x-show="activeStepLabel" x-text="'Current: ' + activeStepLabel"></span>
                </div>
                <div class="provision-steps">
                    <template x-for="item in steps" :key="item.key">
                        <div class="provision-step" :class="`is-${item.status}`">
                            <span class="provision-step-icon" aria-hidden="true">
                                <i x-show="item.status==='completed'" data-lucide="check"></i>
                                <i x-show="item.status==='running'" data-lucide="loader-circle"></i>
                                <i x-show="item.status==='failed'" data-lucide="x"></i>
                                <i x-show="item.status==='pending'" data-lucide="circle"></i>
                            </span>
                            <div class="provision-step-copy">
                                <strong x-text="item.label"></strong>
                                <small x-text="item.message || (item.status === 'pending' ? 'Waiting…' : item.status === 'running' ? 'In progress…' : '')"></small>
                            </div>
                            <time x-text="item.status==='completed' ? 'Done' : item.status==='running' ? 'Running' : item.status==='failed' ? 'Failed' : ''"></time>
                        </div>
                    </template>
                </div>
            </article>

            <aside class="provision-aside">
                <article class="card live-log">
                    <div class="live-log-head">
                        <span><i></i> Live installation log</span>
                        <button type="button" aria-label="Copy log" @click="copyLog()">
                            <i data-lucide="copy"></i>
                        </button>
                    </div>
                    <div class="terminal-lines" x-ref="logPane">
                        <template x-if="logs.length === 0">
                            <p class="terminal-empty"><span>--</span><b>Waiting for installation output…</b></p>
                        </template>
                        <template x-for="line in logs" :key="line.time + line.message">
                            <p><span x-text="line.time"></span><b x-text="line.message"></b></p>
                        </template>
                    </div>
                </article>

                <div class="info-banner compact">
                    <i data-lucide="shield-check"></i>
                    <div>
                        <strong>Safe, repeatable installation</strong>
                        <p>Every operation is queued, retried, and recorded for audit.</p>
                    </div>
                </div>

                <article class="card provision-status-panel is-{{ $panelTone }}" :class="'is-' + panelTone">
                    <span class="provision-status-icon" aria-hidden="true">
                        <i data-lucide="{{ $panelIcon }}" :data-lucide="panelIcon"></i>
                    </span>
                    <div class="provision-status-copy">
                        <strong x-text="panelTitle">{{ $panelTitle }}</strong>
                        <p x-text="panelMessage">{{ $panelMessage }}</p>
                    </div>
                    <form
                        method="post"
                        action="{{ route('servers.provisioning.retry', $server) }}"
                        :action="retryUrl"
                        @if(! $canStart) style="display:none" @endif
                        x-show="canStart"
                        x-cloak
                    >
                        @csrf
                        <button type="submit" class="button button--primary" x-text="actionLabel">{{ $actionLabel }}</button>
                    </form>
                </article>
            </aside>
        </div>
    </div>

    <script>
        function provisioning(config) {
            return {
                steps: @js($server->provisioningSteps),
                logs: [],
                progress: 0,
                completedCount: 0,
                activeStepLabel: '',
                timer: null,
                status: config.status,
                canStart: config.canStart,
                actionLabel: config.actionLabel,
                panelTitle: config.panelTitle,
                panelMessage: config.panelMessage,
                panelTone: config.panelTone,
                panelIcon: config.panelIcon,
                retryUrl: config.retryUrl,
                csrf: config.csrf,
                get statusLabel() {
                    return this.status ? this.status.charAt(0).toUpperCase() + this.status.slice(1) : 'Unknown';
                },
                get statusTone() {
                    return {
                        online: 'success',
                        provisioning: 'running',
                        testing: 'running',
                        pending: 'warning',
                        maintenance: 'warning',
                        failed: 'failed',
                        offline: 'failed',
                    }[this.status] || 'warning';
                },
                start() {
                    this.update();
                    this.timer = setInterval(() => this.poll(), 1200);
                    if (window.Echo) {
                        window.Echo.private('tenants.{{ $server->tenant_id }}.servers.{{ $server->uuid }}')
                            .listen('.server.provisioning.updated', () => this.poll());
                    }
                },
                async poll() {
                    const response = await fetch(config.url, { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    const data = await response.json();
                    this.steps = data.steps;
                    if (data.status) {
                        this.status = data.status;
                        this.syncPanelFromStatus(data);
                    }
                    this.update();
                    if (data.redirect) {
                        clearInterval(this.timer);
                        setTimeout(() => location.href = data.redirect, 500);
                    }
                },
                syncPanelFromStatus(data) {
                    const status = data.status;
                    if (typeof data.needs_attention === 'boolean') {
                        this.canStart = data.needs_attention;
                    }
                    if (status === 'failed') {
                        this.panelTone = 'failed';
                        this.panelIcon = 'triangle-alert';
                        this.panelTitle = 'Provisioning needs attention';
                        this.panelMessage = data.failure_reason
                            || 'A step failed. Review the log, then retry.';
                        this.actionLabel = 'Retry provisioning';
                        return;
                    }
                    if (status === 'pending') {
                        this.panelTone = 'pending';
                        this.panelIcon = 'play';
                        this.panelTitle = 'Provisioning has not started';
                        this.panelMessage = 'Queue the installation job to begin Docker and platform setup.';
                        this.actionLabel = 'Start provisioning';
                        return;
                    }
                    if (status === 'provisioning' || status === 'testing') {
                        this.panelTone = 'running';
                        this.panelIcon = 'loader-circle';
                        this.panelTitle = 'Provisioning in progress';
                        this.panelMessage = 'Steps are running on the server. Progress updates automatically.';
                        this.canStart = false;
                        return;
                    }
                    if (status === 'online') {
                        if (data.needs_attention) {
                            this.panelTone = 'pending';
                            this.panelIcon = 'play';
                            this.panelTitle = 'Re-provision required';
                            this.panelMessage = data.failure_reason
                                || 'Remote services need repair. Re-run provisioning to finish setup.';
                            this.actionLabel = 'Re-provision server';
                            return;
                        }
                        this.panelTone = 'complete';
                        this.panelIcon = 'check-circle-2';
                        this.panelTitle = 'Provisioning complete';
                        this.panelMessage = 'Server is online. Redirecting when verification finishes.';
                        this.canStart = false;
                    }
                },
                update() {
                    const total = this.steps.length || 1;
                    this.completedCount = this.steps.filter(step => step.status === 'completed').length;
                    this.progress = Math.round(this.completedCount / total * 100);
                    const running = this.steps.find(step => step.status === 'running');
                    this.activeStepLabel = running ? running.label : '';
                    this.logs = this.steps.filter(step => step.message).map(step => ({
                        time: new Date(step.updated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
                        message: step.message,
                    }));
                    this.$nextTick(() => {
                        window.renderIcons && window.renderIcons();
                        const pane = this.$refs.logPane;
                        if (pane) pane.scrollTop = pane.scrollHeight;
                    });
                },
                async copyLog() {
                    const text = this.logs.map(line => `${line.time} ${line.message}`).join('\n') || 'No log output yet.';
                    try {
                        await navigator.clipboard.writeText(text);
                    } catch (e) {}
                },
            };
        }
    </script>
</x-dashboard-layout>
