<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\ApplicationCategory;
use App\Services\Applications\CatalogEnvironmentFactory;
use Illuminate\Database\Seeder;

/**
 * Seeds the global marketplace catalog + build packs without demo tenants/users.
 * Safe to re-run (idempotent via slug / firstOrNew).
 *
 * Brand logos are not DB columns: place SVG files at public/images/apps/{slug}.svg
 * (Application::logoUrl() / <x-application-icon> picks them up automatically).
 */
class ApplicationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [];
        foreach ([
            ['Automation', 'workflow', 1],
            ['CMS', 'panels-top-left', 2],
            ['Monitoring', 'activity', 3],
            ['Analytics', 'chart-no-axes-combined', 4],
            ['Developer Tools', 'code-2', 5],
            ['Storage', 'hard-drive', 6],
            ['Security', 'shield-check', 7],
            ['AI', 'sparkles', 8],
            ['Databases', 'database', 9],
            ['Communication', 'messages-square', 10],
            ['Media', 'play-square', 11],
            ['Business', 'briefcase-business', 12],
        ] as [$name, $icon, $position]) {
            $slug = (string) str($name)->slug();
            $categories[$slug] = ApplicationCategory::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'icon' => $icon, 'position' => $position],
            );
        }

        $catalog = [
            ['n8n', 'n8n', 'automation', 'Workflow automation with hundreds of integrations.', 'workflow', '#ea4b71', 'n8nio/n8n', 'latest', 5678, 512, 1, true],
            ['Uptime Kuma', 'uptime-kuma', 'monitoring', 'Beautiful self-hosted uptime monitoring.', 'heart-pulse', '#34b27b', 'louislam/uptime-kuma', '1', 3001, 256, 1, true],
            ['WordPress', 'wordpress', 'cms', 'The world’s most popular publishing platform.', 'panels-top-left', '#287eb3', 'wordpress', 'latest', 80, 512, 5, true],
            ['Ghost', 'ghost', 'cms', 'Modern publishing for professional creators.', 'circle', '#202129', 'ghost', '5-alpine', 2368, 512, 2, true],
            ['Gitea', 'gitea', 'developer-tools', 'Lightweight self-hosted Git collaboration.', 'git-branch', '#62a944', 'gitea/gitea', 'latest', 3000, 512, 5, true],
            ['Nextcloud', 'nextcloud', 'storage', 'Private file sync, sharing, and collaboration.', 'cloud', '#1689d4', 'nextcloud', 'apache', 80, 1024, 10, true],
            ['Umami', 'umami', 'analytics', 'Privacy-focused, open-source web analytics.', 'chart-no-axes-combined', '#6c4cf5', 'ghcr.io/umami-software/umami', 'postgresql-latest', 3000, 512, 2, false],
            ['Vaultwarden', 'vaultwarden', 'security', 'Lightweight Bitwarden-compatible password server.', 'shield-check', '#356ad3', 'vaultwarden/server', 'latest', 80, 256, 1, false],
            ['Metabase', 'metabase', 'analytics', 'Explore data and share business intelligence.', 'bar-chart-3', '#4fa5d8', 'metabase/metabase', 'latest', 3000, 1024, 2, false],
            ['BookStack', 'bookstack', 'cms', 'Simple, self-hosted documentation platform.', 'book-open', '#ef8a35', 'lscr.io/linuxserver/bookstack', 'latest', 80, 512, 2, false],
            ['SFTPGo', 'sftpgo', 'storage', 'Secure file transfer and storage gateway.', 'folder-sync', '#4d83d6', 'drakkan/sftpgo', 'latest', 8080, 512, 5, false],
            ['Open WebUI', 'open-webui', 'ai', 'Feature-rich interface for local AI models.', 'sparkles', '#21242c', 'ghcr.io/open-webui/open-webui', 'main', 8080, 2048, 10, true],
            ['OpenClaw', 'openclaw', 'ai', 'Self-hosted personal AI assistant and automation gateway.', 'bot', '#f06a3c', 'ghcr.io/openclaw/openclaw', 'latest', 18789, 2048, 10, true],
            ['PostgreSQL', 'postgresql', 'databases', 'Reliable open-source relational database.', 'database', '#336791', 'postgres', '16-alpine', 5432, 512, 5, false],
            ['MySQL', 'mysql', 'databases', 'Popular production relational database.', 'database', '#ec8e22', 'mysql', '8.4', 3306, 1024, 5, false],
            ['Redis', 'redis', 'databases', 'Fast in-memory data store and cache.', 'layers', '#dc3d32', 'redis', '7-alpine', 6379, 256, 1, false],
            ['Meilisearch', 'meilisearch', 'developer-tools', 'Lightning-fast application search engine.', 'search', '#ff5c7c', 'getmeili/meilisearch', 'latest', 7700, 512, 2, false],
        ];

        $environmentFactory = app(CatalogEnvironmentFactory::class);
        foreach ($catalog as [$name, $slug, $category, $description, $icon, $accent, $image, $tag, $port, $memory, $disk, $featured]) {
            $app = Application::firstOrNew(['slug' => $slug]);
            $app->fill([
                'category_id' => $categories[$category]->id,
                'name' => $name,
                'description' => $description,
                'icon' => $icon,
                'accent' => $accent,
                'website_url' => $slug === 'openclaw' ? 'https://openclaw.ai' : 'https://'.$slug.'.com',
                'documentation_url' => $slug === 'openclaw' ? 'https://docs.openclaw.ai' : 'https://docs.'.$slug.'.com',
                'license_type' => 'open_source',
                'license_name' => 'Open Source',
                'pricing_model' => 'free',
                'requires_license' => false,
                'docker_image' => $image,
                'default_tag' => $tag,
                'default_port' => $port,
                'minimum_memory_mb' => $memory,
                'minimum_disk_gb' => $disk,
                'featured' => $featured,
                'active' => true,
                'verified' => true,
            ]);
            // Keep popular originals near the top of marketplace ordering
            // (controller: orderByDesc featured, then install_count).
            $popularity = match ($slug) {
                'wordpress' => 240,
                'nextcloud' => 230,
                'n8n' => 220,
                'ghost' => 210,
                'gitea' => 200,
                'uptime-kuma' => 190,
                'open-webui' => 180,
                'openclaw' => 175,
                default => $featured ? 150 : 40,
            };
            if (! $app->exists || (int) $app->install_count < $popularity) {
                $app->install_count = $popularity;
            }
            $app->save();

            $environment = $environmentFactory->schemaFor($slug) ?? [['key' => 'TZ', 'value' => 'Asia/Dubai', 'description' => 'Application timezone', 'secret' => false]];
            $compose = $slug === 'openclaw'
                ? "services:\n  app:\n    image: {$image}:{$tag}\n    command: [node, dist/index.js, gateway, --bind, lan, --port, '18789']\n    restart: unless-stopped\n    ports: ['18789:18789']\n    volumes: [openclaw-data:/home/node/.openclaw]\nvolumes:\n  openclaw-data:"
                : "services:\n  app:\n    image: {$image}:{$tag}\n    restart: unless-stopped\n    networks: [internal]\nnetworks:\n  internal:\n    internal: true";
            $volumes = $slug === 'openclaw'
                ? [['name' => 'openclaw-data', 'path' => '/home/node/.openclaw']]
                : [['name' => 'data', 'path' => '/data']];
            $app->template()->updateOrCreate([], [
                'compose_template' => $compose,
                'environment_schema' => $environment,
                'volume_schema' => $volumes,
                'port_schema' => [['container' => $port]],
                'healthcheck' => 'container',
                'restart_policy' => 'unless-stopped',
                'installation_notes' => $slug === 'openclaw'
                    ? 'Set the public origin to the exact HTTPS domain used for the Control UI. The gateway token is generated automatically; add a model provider from OpenClaw after the first login.'
                    : 'Review credentials and set a production domain before deploying.',
            ]);
        }

        $this->call(ApplicationCatalogExpansionSeeder::class);
        $this->call(BuildPackSeeder::class);
    }
}
