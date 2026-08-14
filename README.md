# Uplary Cloud

A brandable, multi-tenant Docker deployment and server-management control plane built with Laravel 12. Uplary Cloud is the reference identity, while every workspace can configure its own name, colors, company details, and support links. The repository follows the eleven production phases documented in [docs/PHASES.md](docs/PHASES.md).

## Completed phases

**Phase 1 — SaaS foundation:** secure registration and login, email verification, password reset, workspaces, owner/admin/developer/viewer/billing roles, request-scoped tenant resolution, dynamic branding, dashboard UI, settings UI, activity records, Sanctum, Reverb configuration, Redis queue topology, demo seed data, and tenant-isolation tests.

**Phase 2 — Servers:** tenant-scoped server inventory and details, five-stage add-server workflow, encrypted SSH credentials, provider and OS validation, fake/SSH executor abstraction, Redis-ready provisioning jobs, Reverb progress events with polling fallback, provisioning logs, success state, system metrics, authorization policies, and activity records.

**Phase 3 — Docker:** cross-server container inventory and lifecycle actions, image management and guarded removal, volume and network inventory with attachment protection, queued infrastructure operations, Reverb updates, Compose project deployment with strict security validation, and realistic demo resources.

**Phase 4 — Deployment:** curated one-click application marketplace, custom Docker deployments, capacity-aware installation wizard, encrypted secret variables, queued multi-stage deployment engine, Reverb and polling progress updates, terminal logs, runtime Docker records, release history, and guarded rollback.

**Phase 5 — Web applications:** production build packs for Laravel, Node.js, Next.js, and React/Vite; GitHub, GitLab, and Bitbucket source deployment; encrypted private-repository keys; allowlisted build commands; database, Redis, queue, and scheduler services; signed push webhooks; live ten-stage build progress; and commit-aware release history.

**Phase 6 — Networking:** tenant-scoped domain management, live DNS verification, Dockerized Traefik 3.6 routing, application redirects, managed Let's Encrypt certificates, force-HTTPS controls, expiration visibility, and scheduled automatic renewal checks.

**Phase 7 — Operations:** scheduled server and container telemetry, health charts, alert rules and deduplicated incidents, centralized searchable/downloadable logs, workspace activity history, queued backup and restore workflows, encrypted local and S3-compatible destinations with checksum-verified retrieval, retention schedules, image update scanning, safety backups, and rollback-aware updates.

**Phase 8 — Commercial layer:** plan catalog and resource limits, Stripe-ready subscriptions, Checkout, customer portal and signed webhook synchronization, invoices and payment methods, hourly workspace usage, tenant-bound scoped Sanctum API tokens with expiration and IP restrictions, versioned API resources, and secure role-based team invitations and membership controls.

**Phase 9 — Managed infrastructure:** encrypted cloud-provider connections, DigitalOcean and Hetzner API adapters, automatic cloud-init and Docker provisioning, managed compute catalog, queued lifecycle actions, Reverb status events, managed-server plan limits, and a separate infrastructure charge ledger with monthly and prorated resize charges.

**Phase 10 — Production readiness:** public liveness and dependency-aware readiness endpoints, an operator system-health console, automated platform diagnostics, secure response headers, container health checks, and CI release gates.

**Phase 11 — Support operations:** tenant-scoped support tickets, category and priority triage, linked server and deployment context, threaded conversations, status lifecycle management, activity logging, realistic demo conversations, and a responsive support center.

**Phase 12 — Reference-aligned settings:** functional general platform preferences, maintenance preference, real tenant branding uploads, connected settings navigation, platform information, quick actions, and a responsive UI matched to the supplied settings reference.

**Phase 13 — Reference API tokens:** exact Image #1 layout, persistent active/expired/revoked lifecycle, advanced filters, selected-token detail rail, edit and revoke actions, one-time secret reveal, permission scopes, environment and IP restrictions, demo data, and regression coverage.

**Phase 14 — Reference servers:** Image #4 inventory design, real resource totals, tag/status/sort filtering, server capacity and workload columns, utilization indicators, responsive wide-table behavior, working management links, and automated coverage.

## Local setup

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
composer dev
```

`composer dev` starts the HTTP server, a Redis queue worker with **deployments first** (`deployments,provisioning,infrastructure,networking,backups,notifications,monitoring,default`), Reverb, the scheduler (orphaned-queue recovery every minute), log tail, and Vite. Keep Redis running on `:6379` with persistence enabled when possible — Windows Redis without AOF will drop queued jobs on restart while the database still shows **Queued**.

Demo login: `demo@example.com` / `password`.

PHP 8.5 is required. Composer resolves dependencies against PHP 8.5.9 and the production container uses the official PHP 8.5 FPM image.

If a deployment stays **Queued** with empty logs, use **Retry queue** on the deployment page or run `php artisan deployments:recover-orphaned` (also scheduled every minute via `schedule:work`).

## Production services

`docker-compose.yml` provides PHP 8.5 FPM, Nginx, MySQL 8, Redis 7, Laravel Horizon, the Laravel scheduler, and a Reverb WebSocket server. In production, keep `php artisan horizon` running under Supervisor or the hosting platform's process manager. Do not also run `queue:work`: Horizon owns all application queues, including the dedicated `infrastructure` and `provisioning` workers. Run `php artisan horizon:terminate` after each deployment so the process manager restarts Horizon with the latest code and configuration.

The Superadmin **Platform Services** page can show status and start, stop, or restart Horizon and Reverb through Supervisor. Start with [`deploy/supervisor/upentra-services.conf.example`](deploy/supervisor/upentra-services.conf.example), adjust its paths, and configure the programs as `upentra-horizon` and `upentra-reverb` (or override `PLATFORM_HORIZON_PROGRAM` and `PLATFORM_REVERB_PROGRAM`). Install the restricted [`deploy/supervisor/upentra-platform-services.sudoers.example`](deploy/supervisor/upentra-platform-services.sudoers.example) rule with `visudo -cf` validation so the PHP user can run only the eight allowlisted service commands. Direct Supervisor access is attempted first and automatically retries with `sudo -n` after a permission error; set `PLATFORM_SUPERVISORCTL_USE_SUDO=true` to always use sudo or `PLATFORM_SUPERVISORCTL_SUDO_FALLBACK=false` to disable the fallback. Set `PLATFORM_SERVICE_CONTROL_ENABLED=false` when web-based service control is not desired.

Provisioning has a dedicated Horizon supervisor so infrastructure jobs cannot starve it. After deploying queue configuration changes, run `php artisan optimize:clear` and `php artisan horizon:terminate`; Supervisor will restart Horizon and pending `provisioning` jobs will be consumed. The wildcard Horizon environment also keeps workers active when `APP_ENV` is named `prod`, `live`, or another deployment-specific value.

```bash
docker compose up --build -d
docker compose exec app php artisan migrate --force
```

Set `INFRASTRUCTURE_DRIVER=ssh` for live Add Server connection pre-checks and provisioning (phpseclib key/password auth, remote probes, SFTP). Use `INFRASTRUCTURE_DRIVER=fake` only for local demos/tests — the fake driver returns simulated specs (4 CPU / 8 GB / 160 GB) and never opens a real SSH session. Keep `MANAGED_INFRASTRUCTURE_DRIVER=fake` until verified DigitalOcean or Hetzner credentials are configured; set it to `production` for managed cloud create.

## Verification

The CI workflow runs on PHP 8.5. The local host must also provide PHP 8.5, or Docker Desktop must be running, before backend tests are executed.

```bash
php artisan test
npm run build
php artisan platform:doctor
```
