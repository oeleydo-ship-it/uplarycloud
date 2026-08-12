<x-dashboard-layout title="Provisioning {{ $server->name }}">
    <div class="provision-page" x-data="provisioning('{{ route('servers.provisioning.status', $server) }}')" x-init="start()">
        <div class="page-heading">
            <div>
                <p class="breadcrumb">
                    <a href="{{ route('servers.index') }}">Servers</a> / {{ $server->name }} / Provision
                </p>
                <h1>Provisioning {{ $server->name }}</h1>
                <p>Docker and platform services are being installed securely.</p>
            </div>
            <span class="status status--{{ $server->status->tone() }}"><i></i> {{ $server->status->label() }}</span>
        </div>

        <div class="provision-layout">
            <article class="card provision-card">
                <div class="provision-summary">
                    <span class="provision-server-icon"><i data-lucide="server-cog"></i></span>
                    <div>
                        <h2>Preparing your server</h2>
                        <p>This normally takes 2–5 minutes. You may safely leave this page.</p>
                    </div>
                    <strong x-text="progress + '%'">0%</strong>
                </div>
                <div class="provision-progress"><span :style="`width:${progress}%`"></span></div>
                <div class="provision-steps">
                    <template x-for="item in steps" :key="item.key">
                        <div class="provision-step" :class="`is-${item.status}`">
                            <span>
                                <i x-show="item.status==='completed'" data-lucide="check"></i>
                                <i x-show="item.status==='running'" data-lucide="loader-circle"></i>
                                <i x-show="item.status==='pending'" data-lucide="circle"></i>
                            </span>
                            <div>
                                <strong x-text="item.label"></strong>
                                <small x-text="item.message || 'Waiting…'"></small>
                            </div>
                            <time x-text="item.status==='completed' ? 'Done' : item.status==='running' ? 'Running' : ''"></time>
                        </div>
                    </template>
                </div>
            </article>

            <aside class="provision-aside">
                <article class="card live-log">
                    <div class="live-log-head">
                        <span><i></i> Live installation log</span>
                        <button type="button" aria-label="Copy log"><i data-lucide="copy"></i></button>
                    </div>
                    <div class="terminal-lines">
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
                @if($needsAttention ?? false)
                    <article class="card provision-failed">
                        <i data-lucide="{{ $server->status === \App\Enums\ServerStatus::Failed ? 'triangle-alert' : 'play' }}"></i>
                        <div>
                            <strong>{{ $server->status === \App\Enums\ServerStatus::Failed ? 'Provisioning needs attention' : ($server->status === \App\Enums\ServerStatus::Online ? 'Re-provision required' : 'Provisioning has not started') }}</strong>
                            <p>{{ $attentionMessage ?: 'Queue the installation job to begin Docker and platform setup.' }}</p>
                        </div>
                        <form method="post" action="{{ route('servers.provisioning.retry', $server) }}">
                            @csrf
                            <button class="button button--primary">{{ $server->status === \App\Enums\ServerStatus::Failed ? 'Retry provisioning' : ($server->status === \App\Enums\ServerStatus::Online ? 'Re-provision server' : 'Start provisioning') }}</button>
                        </form>
                    </article>
                @endif
            </aside>
        </div>
    </div>

    <script>
        function provisioning(url) {
            return {
                steps: @js($server->provisioningSteps),
                logs: [],
                progress: 0,
                timer: null,
                start() {
                    this.update();
                    this.timer = setInterval(() => this.poll(), 1200);
                    if (window.Echo) {
                        window.Echo.private('tenants.{{ $server->tenant_id }}.servers.{{ $server->uuid }}')
                            .listen('.server.provisioning.updated', () => this.poll());
                    }
                },
                async poll() {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    const data = await response.json();
                    this.steps = data.steps;
                    this.update();
                    if (data.redirect) {
                        clearInterval(this.timer);
                        setTimeout(() => location.href = data.redirect, 500);
                    }
                },
                update() {
                    const done = this.steps.filter(step => step.status === 'completed').length;
                    this.progress = Math.round(done / this.steps.length * 100);
                    this.logs = this.steps.filter(step => step.message).map(step => ({
                        time: new Date(step.updated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
                        message: step.message,
                    }));
                    this.$nextTick(() => window.renderIcons && window.renderIcons());
                },
            };
        }
    </script>
</x-dashboard-layout>
