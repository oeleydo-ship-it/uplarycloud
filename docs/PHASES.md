# Production implementation phases

The application follows the supplied phased build order. A phase is complete only after its schema, authorization, services, UI, validation, activity logging, and tests are green.

1. **SaaS foundation — complete** — authentication, tenant workspaces, roles, dashboard, settings, dynamic branding.
2. **Servers — complete** — CRUD, encrypted credentials, connection testing, fake/SSH executors, queued provisioning, Reverb progress.
3. **Docker — complete** — tenant-scoped containers, images, volumes, networks, queued actions, deletion safeguards, Compose projects and safe Compose validation.
4. **Deployment — complete** — curated marketplace and custom Docker workflows, capacity-aware install wizard, encrypted variables, queued deployment engine, Reverb progress, live logs, releases and rollback.
5. **Web applications — complete** — Laravel, Node.js, Next.js and React/Vite build packs, GitHub/GitLab/Bitbucket repositories, encrypted deploy keys, validated build commands, optional databases/Redis/queue/scheduler sidecars, signed auto-deploy webhooks, live build progress and Git-aware releases.
6. **Networking — complete** — tenant-scoped domains, DNS checks, Dockerized Traefik routes, redirects, Let's Encrypt certificates, force HTTPS, expiration monitoring and automatic renewal scheduling.
7. **Operations — complete** — scheduled server/container metrics, health dashboards, alert rules and incidents, centralized searchable logs, activity audit history, queued backups/restores, encrypted local and S3-compatible destinations, checksum-verified remote retrieval, retention schedules, image update detection, safety backups and rollback-aware updates.
8. **Commercial layer — complete** — Free, Starter, Pro and Business plans, enforced limits, Stripe Checkout/customer portal/signed webhooks, local billing simulation, subscriptions, invoices, payment methods, hourly usage metering, tenant-bound scoped Sanctum tokens with IP restrictions, versioned API resources, and role-safe team invitations and administration.
9. **Managed infrastructure — complete** — encrypted tenant provider connections, verified DigitalOcean and Hetzner production adapters, a safe local provider simulator, automatic cloud-init and Docker provisioning, managed compute plans and regions, queued create/sync/restart/resize/rebuild/destroy operations, Reverb lifecycle events, resource limits, prorated resize adjustments, recurring compute charges, and infrastructure billing separated from SaaS subscriptions.
10. **Production readiness — complete** — public liveness and dependency-aware readiness endpoints, a tenant-authenticated system-health console, reusable platform diagnostics, secure default response headers, Docker health checks, and CI readiness verification.

11. **Support operations — complete** — tenant-scoped support tickets, category and priority triage, server and application context, threaded replies, ticket status lifecycle management, activity logging, demo conversations, responsive UI, and isolation tests.

12. **Reference-aligned settings — complete** — persisted general workspace preferences, role-protected maintenance controls, tenant logo and favicon uploads, fully connected settings navigation, platform information and quick actions, responsive reference-matched UI, and feature tests.

13. **Reference API tokens — complete** — exact supplied API-token layout, searchable and filterable credentials, selected detail rail, persistent revocation, editing, permission scopes, expiration, environment and IP controls, one-time token reveal, demo records, responsive behavior, and tests.

14. **Reference servers — complete** — exact supplied server inventory composition, real container/volume/backup/application counts, tag and status filters, sorting, Docker and resource details, utilization indicators, management actions, responsive behavior, and tests.

## Runtime services

Production uses PHP 8.5, MySQL 8+, Redis-backed cache and queues, and Laravel Reverb. Composer resolves against PHP 8.5.9. Queue names are defined in `config/infrastructure.php`. Local development defaults to the fake infrastructure driver so no real server is touched.

## Demo access

After `php artisan migrate:fresh --seed`, sign in with `demo@example.com` / `password`.
