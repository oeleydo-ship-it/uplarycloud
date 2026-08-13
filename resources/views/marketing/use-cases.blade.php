<x-marketing-layout title="Use cases" description="Uplary Cloud for agencies, independent developers, and product teams.">
    <div class="mkt-wrap mkt-page">
        <span class="mkt-kicker">Use cases</span>
        <h1 class="mkt-title">Same console. Different kinds of work.</h1>
        <p class="mkt-lead">Whether you run client stacks, a handful of side projects, or a production team, the objects stay the same: servers, apps, domains, and operations.</p>

        <div class="mkt-use" style="margin-top:36px">
            <article class="mkt-card">
                <span class="mkt-icon"><i data-lucide="briefcase-business"></i></span>
                <h2>Agencies</h2>
                <p>Keep every client on a recognizable stack. Marketplace installs cover the usual CMS and analytics request; Git and Compose cover the rest. New staff can see what is running without a handover doc.</p>
                <ul>
                    <li>Repeatable WordPress, Ghost, and Nextcloud installs</li>
                    <li>Domains and certificates on the client’s hostnames</li>
                    <li>Backups and tickets instead of a shared password vault</li>
                </ul>
            </article>
            <article class="mkt-card">
                <span class="mkt-icon"><i data-lucide="person-standing"></i></span>
                <h2>Indie builders</h2>
                <p>One cheap VPS should not require a second career in ops. Connect the box, ship from Git, and put a domain in front. Free and Starter exist so you can start before you have a team.</p>
                <ul>
                    <li>Single-server workspaces that stay readable</li>
                    <li>Marketplace apps for the tools around your product</li>
                    <li>Upgrade to Pro when backups and alerts start to matter</li>
                </ul>
            </article>
            <article class="mkt-card">
                <span class="mkt-icon"><i data-lucide="users"></i></span>
                <h2>Product teams</h2>
                <p>Shared roles, API tokens, and plan limits keep production from becoming one person’s laptop. Monitoring, alerts, and audit-friendly activity sit next to the deployments they describe.</p>
                <ul>
                    <li>Invite owners, developers, and billing without sharing root</li>
                    <li>Git deploys with verify, redeploy, and rollback</li>
                    <li>Business plan for SLA support and audit exports</li>
                </ul>
            </article>
            <article class="mkt-card">
                <span class="mkt-icon"><i data-lucide="cloud"></i></span>
                <h2>Mixed infrastructure</h2>
                <p>Not every host has to be managed by Uplary. Connect the machines you care about, leave the rest alone, and add managed cloud servers only when you want provisioning from the console.</p>
                <ul>
                    <li>Bring-your-own servers alongside managed instances</li>
                    <li>Optional personal cloud API tokens</li>
                    <li>One workspace picture either way</li>
                </ul>
            </article>
        </div>

        <section class="mkt-band" style="margin-top:28px">
            <div>
                <h2>See which plan fits the workload.</h2>
                <p>Limits and feature gates are listed on pricing — no sales call required to read them.</p>
            </div>
            <a class="button button--primary" href="{{ route('marketing.pricing') }}">View pricing</a>
        </section>
    </div>
</x-marketing-layout>
