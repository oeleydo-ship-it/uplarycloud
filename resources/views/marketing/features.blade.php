<x-marketing-layout title="Features" description="Servers, marketplace apps, Git deploys, domains, SSL, and monitoring in one control plane.">
    <div class="mkt-wrap mkt-page">
        <span class="mkt-kicker">Product</span>
        <h1 class="mkt-title">Everything the console is for.</h1>
        <p class="mkt-lead">Uplary Cloud covers the path from a connected host to a live, observed application. Each capability is a first-class object — not a script you have to remember.</p>

        <section class="mkt-feature">
            <h2>Servers</h2>
            <div>
                <p>Bring your own VPS over SSH or provision managed DigitalOcean and Hetzner hosts from the platform. Provisioning installs Docker, the reverse proxy, and a metrics collector, then keeps connection health visible.</p>
                <ul>
                    <li><i data-lucide="check"></i> Existing servers and managed cloud instances</li>
                    <li><i data-lucide="check"></i> Connection tests before you commit a host</li>
                    <li><i data-lucide="check"></i> Resource usage and provisioning status in one place</li>
                </ul>
            </div>
        </section>

        <section class="mkt-feature">
            <h2>Applications</h2>
            <div>
                <p>Install curated marketplace apps, run custom Docker images, or compose multi-service stacks. Installed workloads stay listed with their server, ports, and health.</p>
                <ul>
                    <li><i data-lucide="check"></i> Marketplace catalog with WordPress, n8n, Nextcloud, and more</li>
                    <li><i data-lucide="check"></i> Custom images and Docker Compose projects</li>
                    <li><i data-lucide="check"></i> Restart policies, resource limits, and install history</li>
                </ul>
            </div>
        </section>

        <section class="mkt-feature">
            <h2>Git deploys</h2>
            <div>
                <p>Point a repository at a build pack. Uplary builds a release you can verify, redeploy, or roll back. Webhooks keep production in step with the branch you trust.</p>
                <ul>
                    <li><i data-lucide="check"></i> GitHub and generic Git remotes</li>
                    <li><i data-lucide="check"></i> Build packs for common runtimes</li>
                    <li><i data-lucide="check"></i> Release history with rollback</li>
                </ul>
            </div>
        </section>

        <section class="mkt-feature">
            <h2>Domains &amp; SSL</h2>
            <div>
                <p>Domains are attached to deployments. Verify DNS, configure the proxy, and request a certificate without dropping to nginx on the host.</p>
                <ul>
                    <li><i data-lucide="check"></i> Hostname records tied to an application</li>
                    <li><i data-lucide="check"></i> DNS verification and proxy configuration</li>
                    <li><i data-lucide="check"></i> Certificate issuance from the same domain object</li>
                </ul>
            </div>
        </section>

        <section class="mkt-feature">
            <h2>Marketplace</h2>
            <div>
                <p>A maintained catalog of self-hosted software with sensible defaults: image, port, memory, and volumes. Install into a connected server and continue from the same workspace.</p>
                <ul>
                    <li><i data-lucide="check"></i> Categories from CMS to automation and AI</li>
                    <li><i data-lucide="check"></i> License and pricing hints for commercial images</li>
                    <li><i data-lucide="check"></i> One install flow instead of a fresh compose file each time</li>
                </ul>
            </div>
        </section>

        <section class="mkt-feature">
            <h2>Monitoring</h2>
            <div>
                <p>Host and application metrics live next to the resources they describe. Alert rules and incidents replace “did anyone check the VPS?”</p>
                <ul>
                    <li><i data-lucide="check"></i> Server and container metrics</li>
                    <li><i data-lucide="check"></i> Threshold alerts and incident workflow</li>
                    <li><i data-lucide="check"></i> Backups, logs, and activity for the same tenant</li>
                </ul>
            </div>
        </section>

        <section class="mkt-band" style="margin-top:24px">
            <div>
                <h2>See how plans map to these gates.</h2>
                <p>Free through Business unlock marketplace, Git, managed servers, and support in stages.</p>
            </div>
            <a class="button button--primary" href="{{ route('marketing.pricing') }}">Compare pricing</a>
        </section>
    </div>
</x-marketing-layout>
