<?php

namespace App\Support;

use Illuminate\Support\Collection;

class MarketingPosts
{
    /**
     * @return Collection<int, object>
     */
    public static function all(): Collection
    {
        return collect(static::entries())->map(fn (array $post) => (object) $post);
    }

    public static function find(string $slug): ?object
    {
        $post = collect(static::entries())->firstWhere('slug', $slug);

        return $post ? (object) $post : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entries(): array
    {
        return [
            [
                'slug' => 'from-server-to-production',
                'title' => 'From a bare server to production in three steps',
                'excerpt' => 'Connect a host, pick an app or a Git repo, then attach a domain and certificate. The control plane keeps the rest visible.',
                'published_at' => '2026-07-22',
                'read_minutes' => 5,
                'category' => 'Product',
                'paragraphs' => [
                    'Most teams do not fail at Docker itself. They fail at the operational glue around it: SSH keys, reverse proxies, certificate renewal, and the question of what is actually running on which host.',
                    'Uplary Cloud is built around a short path. First you connect a server — your own VPS or a managed cloud instance. The control plane installs the runtime pieces it needs and reports health back to the console.',
                    'Second, you ship an application. That can be a marketplace install such as WordPress or n8n, a custom image, or a Git repository with a build pack. Releases, rollbacks, and logs stay attached to that deployment.',
                    'Third, you put it on the internet. Domains, DNS checks, and TLS certificates are first-class objects, not a weekend of nginx snippets. Monitoring and alerts sit next to the same resources so you are not bouncing between five tools to answer one outage.',
                    'The point is not to hide infrastructure. It is to make the next action obvious: connect, deploy, go live — and keep a clear record of what changed.',
                ],
            ],
            [
                'slug' => 'agencies-and-client-stacks',
                'title' => 'How agencies keep client stacks from becoming a spreadsheet',
                'excerpt' => 'One workspace per client, shared operations, and a marketplace that covers the usual CMS, analytics, and automation stack.',
                'published_at' => '2026-08-02',
                'read_minutes' => 6,
                'category' => 'Use cases',
                'paragraphs' => [
                    'Agency infrastructure often starts tidy and ends as a shared Notion doc of IPs, panels, and “ask Sam for the SSH key.” That works until someone is on holiday and a certificate expires.',
                    'A control plane does not replace your engineers. It gives every client stack the same shape: servers, applications, domains, backups, and a ticket when something is wrong. New teammates can see the same picture without a walkthrough.',
                    'Marketplace apps cover the usual client request list — WordPress, Ghost, Nextcloud, Umami, Uptime Kuma — without a custom compose file for each one. When a client needs something custom, Git deploys and Docker Compose still live in the same console.',
                    'Billing and plans keep the commercial side honest. A starter workspace is enough for a brochure site. A production client with backups, alerts, and a team can sit on Pro or Business without you inventing a new ops process.',
                    'If you already run twenty VPS boxes, you do not have to migrate them overnight. Connect the hosts you care about first, then move domains and apps as renewals come up.',
                ],
            ],
            [
                'slug' => 'git-domains-and-ssl',
                'title' => 'Git deploys, domains, and SSL without the usual scramble',
                'excerpt' => 'Build from a repository, point a hostname, and let the proxy issue a certificate. Then watch the release, not the terminal scroll.',
                'published_at' => '2026-08-10',
                'read_minutes' => 4,
                'category' => 'Operations',
                'paragraphs' => [
                    'A modern app deploy is rarely “docker run” and done. You need a build, a health check, a hostname, and a certificate that actually renews. Doing that by hand on every server is how Friday night incidents start.',
                    'Uplary treats Git as a first-class source. Connect a repository, choose a branch and build pack, and the platform produces a release you can verify, redeploy, or roll back. Webhooks keep that loop automatic when you push.',
                    'Domains are attached to deployments, not buried in a host file. You can verify DNS, configure the proxy, and request a certificate from the same record. When something fails, the status is on the domain — not in an SSH session you already closed.',
                    'Monitoring and backups sit one click away from those same resources. You do not need a second product to know CPU is high, and you do not need a third to snapshot the volume before a risky upgrade.',
                    'That is the operating model: fewer bespoke scripts, more shared objects, and a console that still lets you drop to Docker when you need to.',
                ],
            ],
        ];
    }
}
