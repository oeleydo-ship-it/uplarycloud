<x-marketing-layout title="Home" description="Connect servers, deploy apps, and run production with a clear control plane.">
    <div class="mkt-wrap">
        <section class="mkt-hero">
            <div>
                <span class="mkt-kicker">Docker operations, simplified</span>
                <h1 class="mkt-title">From server to production without the scramble.</h1>
                <p class="mkt-lead">Uplary Cloud is the control plane for servers, marketplace apps, Git deploys, domains, and SSL. Connect a host, ship a workload, and keep operations in one console.</p>
                <div class="mkt-actions">
                    <a class="button button--primary" href="{{ auth()->check() ? route('dashboard') : route('register') }}">Get started</a>
                    <a class="button button--secondary" href="{{ route('marketing.features') }}">See features</a>
                </div>
            </div>
            <div class="mkt-preview" aria-hidden="true">
                <div class="mkt-preview-bar">
                    <i class="is-live"></i><i></i><i></i>
                    <span>Production workspace</span>
                </div>
                <div class="mkt-preview-body">
                    <div class="mkt-preview-side">
                        <span class="is-on"><i data-lucide="layout-dashboard"></i> Overview</span>
                        <span><i data-lucide="server"></i> Servers</span>
                        <span><i data-lucide="box"></i> Apps</span>
                        <span><i data-lucide="globe-2"></i> Domains</span>
                    </div>
                    <div class="mkt-preview-main">
                        <strong>Running this week</strong>
                        <div class="mkt-preview-stats">
                            <article><small>Servers</small><b>3</b></article>
                            <article><small>Applications</small><b>12</b></article>
                        </div>
                        <div class="mkt-preview-row">
                            <span>wordpress · cms</span>
                            <em>Healthy</em>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mkt-section">
            <div class="mkt-section-head">
                <h2>Built for the work after SSH.</h2>
                <p>A shared picture of infrastructure — not another panel that forgets what you deployed last month.</p>
            </div>
            <div class="mkt-grid mkt-grid--3">
                <article class="mkt-card">
                    <span class="mkt-icon"><i data-lucide="server"></i></span>
                    <h3>Your servers, or ours</h3>
                    <p>Connect an existing VPS or provision managed cloud hosts. Docker, proxy, and health checks land in the same workspace.</p>
                </article>
                <article class="mkt-card">
                    <span class="mkt-icon"><i data-lucide="boxes"></i></span>
                    <h3>Apps you actually run</h3>
                    <p>Install from the marketplace, ship a custom image, or build from Git. Releases and rollbacks stay attached to the app.</p>
                </article>
                <article class="mkt-card">
                    <span class="mkt-icon"><i data-lucide="shield-check"></i></span>
                    <h3>Domains, TLS, and eyes on it</h3>
                    <p>Point a hostname, issue a certificate, and watch metrics and alerts next to the same resources.</p>
                </article>
            </div>
        </section>

        <section class="mkt-section">
            <div class="mkt-section-head">
                <h2>How it works</h2>
                <p>Three steps. The console keeps the rest — logs, health, and the next deploy — in view.</p>
            </div>
            <div class="mkt-steps">
                <article class="mkt-step">
                    <span class="mkt-step-num">1</span>
                    <h3>Connect a server</h3>
                    <p>Add your own host or launch a managed instance. The control plane prepares Docker and reports status back.</p>
                </article>
                <article class="mkt-step">
                    <span class="mkt-step-num">2</span>
                    <h3>Deploy an app</h3>
                    <p>Pick a marketplace app, a Git repository, or a Compose project. Verify the release before you expose it.</p>
                </article>
                <article class="mkt-step">
                    <span class="mkt-step-num">3</span>
                    <h3>Go live</h3>
                    <p>Attach a domain, request SSL, and keep backups and monitoring on the same record.</p>
                </article>
            </div>
        </section>

        <section class="mkt-section">
            <div class="mkt-section-head">
                <span class="mkt-kicker">One operational workspace</span>
                <h2>Everything around the deployment, included.</h2>
                <p>Uplary Cloud brings the routine parts of running production into one clear workflow, so your team can spend less time stitching tools together.</p>
            </div>
            <div class="mkt-grid mkt-grid--3">
                <article class="mkt-card">
                    <span class="mkt-icon"><i data-lucide="git-branch"></i></span>
                    <h3>Git-powered deployments</h3>
                    <p>Connect a repository, choose a branch, and deploy on demand. Build output, release history, and rollback controls stay with the application.</p>
                </article>
                <article class="mkt-card">
                    <span class="mkt-icon"><i data-lucide="scroll-text"></i></span>
                    <h3>Live logs in context</h3>
                    <p>Follow build, deploy, proxy, and container logs without jumping between SSH sessions. Filter the signal and troubleshoot from the same app view.</p>
                </article>
                <article class="mkt-card">
                    <span class="mkt-icon"><i data-lucide="activity"></i></span>
                    <h3>Health and resource metrics</h3>
                    <p>See container health, CPU, memory, disk, and uptime at a glance. Catch unhealthy workloads before they become customer-facing incidents.</p>
                </article>
                <article class="mkt-card">
                    <span class="mkt-icon"><i data-lucide="database-backup"></i></span>
                    <h3>Scheduled backups</h3>
                    <p>Protect databases and application data with repeatable backup schedules. Keep recovery points attached to the workload they belong to.</p>
                </article>
                <article class="mkt-card">
                    <span class="mkt-icon"><i data-lucide="users"></i></span>
                    <h3>Teams and permissions</h3>
                    <p>Invite the people who ship and support your software. Give teams a shared operational picture without sharing root credentials.</p>
                </article>
                <article class="mkt-card">
                    <span class="mkt-icon"><i data-lucide="bell-ring"></i></span>
                    <h3>Alerts that lead somewhere</h3>
                    <p>Turn failed deployments, health changes, and certificate issues into actionable notifications with the affected resource already identified.</p>
                </article>
            </div>
        </section>

        <section class="mkt-section">
            <div class="mkt-section-head">
                <span class="mkt-kicker">Built for production</span>
                <h2>Control from the first deploy to the hundredth.</h2>
                <p>Start with one application and keep the same dependable workflow as your infrastructure grows.</p>
            </div>
            <article class="mkt-feature">
                <h2>Deploy with confidence</h2>
                <div>
                    <p>Every release follows a visible path from source to running container. Review what changed, inspect the build, and return to a known release when needed.</p>
                    <ul>
                        <li><i data-lucide="check-circle-2"></i> Repeatable builds from Git, images, or Docker Compose</li>
                        <li><i data-lucide="check-circle-2"></i> Environment variables managed per application</li>
                        <li><i data-lucide="check-circle-2"></i> Release history with fast rollback controls</li>
                    </ul>
                </div>
            </article>
            <article class="mkt-feature">
                <h2>Keep traffic secure</h2>
                <div>
                    <p>Route applications through managed domains and HTTPS without hand-editing proxy configuration on every server.</p>
                    <ul>
                        <li><i data-lucide="check-circle-2"></i> Automatic reverse-proxy configuration</li>
                        <li><i data-lucide="check-circle-2"></i> SSL issuance and renewal tracking</li>
                        <li><i data-lucide="check-circle-2"></i> Clear domain-to-application routing</li>
                    </ul>
                </div>
            </article>
            <article class="mkt-feature">
                <h2>Operate without guesswork</h2>
                <div>
                    <p>Servers, applications, domains, databases, and activity stay connected in one inventory, giving everyone the same answer during a busy release or incident.</p>
                    <ul>
                        <li><i data-lucide="check-circle-2"></i> Centralized server and workload status</li>
                        <li><i data-lucide="check-circle-2"></i> Searchable operational activity and logs</li>
                        <li><i data-lucide="check-circle-2"></i> A consistent workflow across every environment</li>
                    </ul>
                </div>
            </article>
        </section>

        <section class="mkt-section">
            <div class="mkt-section-head">
                <h2>Featured apps</h2>
                <p>A sample of what teams install first. The full catalog lives in the workspace after you sign in.</p>
            </div>
            <div class="mkt-apps">
                @foreach($applications as $application)
                    <div class="mkt-app">
                        @if($application instanceof \App\Models\Application)
                            <x-application-icon :application="$application" />
                        @else
                            <span class="app-icon app-icon--md" style="--app-accent: {{ $application->accent }}">
                                <i data-lucide="{{ $application->icon }}"></i>
                            </span>
                        @endif
                        <span>{{ $application->name }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mkt-band">
            <div>
                <h2>Ready when you are.</h2>
                <p>Create a workspace, connect a server, and ship the first app the same afternoon.</p>
            </div>
            <div class="mkt-actions" style="margin:0">
                <a class="button button--primary" href="{{ route('register') }}">Create account</a>
                <a class="button button--secondary" href="{{ route('marketing.pricing') }}">View pricing</a>
            </div>
        </section>
    </div>
</x-marketing-layout>
