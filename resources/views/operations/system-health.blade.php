<x-dashboard-layout title="System Health">
    @php
        $healthyChecks = collect($report['checks'])->where('status', 'pass')->count();
        $totalChecks = count($report['checks']);
    @endphp

    <div class="page-heading">
        <div><p class="breadcrumb">Operations / System Health</p><h1>System health</h1><p>Production readiness across the control plane and its core dependencies.</p></div>
        <div class="heading-actions"><a href="{{ route('system-health') }}" class="button button--secondary"><i data-lucide="refresh-cw"></i> Run checks</a></div>
    </div>

    <section class="stats-grid system-health-stats">
        <x-stat-card label="Readiness" :value="$report['status'] === 'ready' ? 'Ready' : 'Attention'" :detail="$healthyChecks.' of '.$totalChecks.' checks passing'" icon="shield-check" :tone="$report['status'] === 'ready' ? 'green' : 'orange'" />
        <x-stat-card label="Failed jobs" :value="$failedJobs" detail="Queue failures requiring review" icon="list-restart" :tone="$failedJobs ? 'orange' : 'green'" />
        <x-stat-card label="Environment" :value="str($runtime['Environment'])->title()" detail="Current application mode" icon="boxes" tone="blue" />
        <x-stat-card label="Last checked" :value="now()->format('H:i')" :detail="now()->format('M j, Y')" icon="clock-3" tone="purple" />
    </section>

    <section class="health-layout">
        <article class="card health-checks-card">
            <div class="card-head"><div><h2>Readiness checks</h2><p>These checks are also used by automated deployments and containers.</p></div><span class="health-summary health-summary--{{ $report['status'] }}">{{ str($report['status'])->replace('_', ' ')->title() }}</span></div>
            <div class="health-check-list">
                @foreach($report['checks'] as $name => $check)
                    <div class="health-check-row">
                        <span class="health-check-icon health-check-icon--{{ $check['status'] }}"><i data-lucide="{{ $check['status'] === 'pass' ? 'check' : 'x' }}"></i></span>
                        <span><strong>{{ str($name)->replace('_', ' ')->title() }}</strong><small>{{ match($name) { 'application_key' => 'Application encryption identity is configured.', 'database' => 'The primary database accepts queries.', 'migrations' => 'The database schema has been initialized.', 'cache' => 'The configured cache can persist and retrieve values.', 'storage' => 'Application storage is available for runtime files.', default => 'Platform dependency check.' } }}</small></span>
                        <span><strong>{{ strtoupper($check['status']) }}</strong><small>{{ number_format($check['latency_ms'], 2) }} ms</small></span>
                    </div>
                @endforeach
            </div>
        </article>

        <aside class="health-aside">
            <article class="card runtime-card">
                <div class="card-head"><div><h2>Runtime</h2><p>Non-sensitive platform configuration.</p></div></div>
                <dl>
                    @foreach($runtime as $label => $value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </article>
            <article class="card health-endpoints-card">
                <div class="card-head"><div><h2>Health endpoints</h2><p>Use these URLs with load balancers and monitors.</p></div></div>
                <div><span><strong>Liveness</strong><code>{{ route('health.live', absolute: false) }}</code></span><span class="status status--success"><i></i> Public</span></div>
                <div><span><strong>Readiness</strong><code>{{ route('health.ready', absolute: false) }}</code></span><span class="status status--success"><i></i> Public</span></div>
            </article>
        </aside>
    </section>
</x-dashboard-layout>
