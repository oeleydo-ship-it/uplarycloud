<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\ApplicationCategory;
use App\Services\Applications\CatalogEnvironmentFactory;
use Illuminate\Database\Seeder;

class ApplicationCatalogExpansionSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [];
        foreach ([
            ['automation', 'Automation', 'workflow', 1],
            ['monitoring', 'Monitoring', 'activity', 3],
            ['analytics', 'Analytics', 'chart-no-axes-combined', 4],
            ['developer-tools', 'Developer Tools', 'code-2', 5],
            ['databases', 'Databases', 'database', 9],
            ['communication', 'Communication', 'messages-square', 10],
            ['media', 'Media', 'play-square', 11],
            ['business', 'Business', 'briefcase-business', 12],
        ] as [$slug, $name, $icon, $position]) {
            $categories[$slug] = ApplicationCategory::updateOrCreate(
                ['slug' => $slug],
                compact('name', 'icon', 'position'),
            );
        }

        $catalog = [
            ['Grafana','grafana','analytics','Visualize metrics, logs, and traces in operational dashboards.','chart-spline','#f46800','grafana/grafana','latest',3000,512,2,true,'open_source','AGPL-3.0','free',null,false,'https://grafana.com/oss/grafana/','https://grafana.com/docs/grafana/latest/',['/var/lib/grafana']],
            ['Prometheus','prometheus','monitoring','Collect and query time-series metrics with powerful alerting.','activity','#e6522c','prom/prometheus','latest',9090,512,5,true,'open_source','Apache-2.0','free',null,false,'https://prometheus.io/','https://prometheus.io/docs/',['/prometheus']],
            ['MariaDB','mariadb','databases','Production-ready community relational database.','database','#003545','mariadb','11.4',3306,1024,5,false,'open_source','GPL-2.0','free',null,false,'https://mariadb.org/','https://mariadb.com/kb/en/documentation/',['/var/lib/mysql']],
            ['MongoDB Community','mongodb','databases','Document database for modern application workloads.','database-zap','#13aa52','mongo','8',27017,1024,5,false,'source_available','SSPL-1.0','free',null,false,'https://www.mongodb.com/products/self-managed/community-edition','https://www.mongodb.com/docs/manual/',['/data/db']],
            ['RabbitMQ','rabbitmq','developer-tools','Message broker with the management console included.','waypoints','#ff6600','rabbitmq','4-management',15672,512,2,false,'open_source','MPL-2.0','free',null,false,'https://www.rabbitmq.com/','https://www.rabbitmq.com/docs',['/var/lib/rabbitmq']],
            ['Home Assistant','home-assistant','automation','Local-first home automation and device control.','house-plug','#18bcf2','ghcr.io/home-assistant/home-assistant','stable',8123,1024,5,false,'open_source','Apache-2.0','free',null,false,'https://www.home-assistant.io/','https://www.home-assistant.io/installation/',['/config']],
            ['Jellyfin','jellyfin','media','Free media server for streaming your own library.','play','#aa5cc3','jellyfin/jellyfin','latest',8096,1024,10,false,'open_source','GPL-2.0','free',null,false,'https://jellyfin.org/','https://jellyfin.org/docs/',['/config','/cache']],
            ['Matomo','matomo','analytics','Privacy-respecting web analytics with full data ownership.','chart-area','#3152a0','matomo','5-apache',80,1024,5,false,'open_source','GPL-3.0','free',null,false,'https://matomo.org/','https://matomo.org/guide/',['/var/www/html']],
            ['Forgejo','forgejo','developer-tools','Community-driven, lightweight software forge.','git-fork','#fb923c','codeberg.org/forgejo/forgejo','16',3000,512,5,false,'open_source','GPL-3.0','free',null,false,'https://forgejo.org/','https://forgejo.org/docs/latest/',['/data']],
            ['Roundcube','roundcube','communication','Browser-based IMAP email client with a modern interface.','mail','#1f70c1','roundcube/roundcubemail','latest',80,512,2,false,'open_source','GPL-3.0','free',null,false,'https://roundcube.net/','https://github.com/roundcube/roundcubemail/wiki',['/var/roundcube/config','/var/roundcube/db']],
            ['GitLab Enterprise Edition','gitlab-ee','developer-tools','Complete DevSecOps platform with optional paid enterprise features.','gitlab','#fc6d26','gitlab/gitlab-ee','latest',80,4096,20,true,'source_available','GitLab Enterprise Edition License','freemium','https://about.gitlab.com/pricing/',false,'https://about.gitlab.com/','https://docs.gitlab.com/install/docker/',['/etc/gitlab','/var/log/gitlab','/var/opt/gitlab']],
            ['Portainer Business Edition','portainer-business','developer-tools','Commercial container management with enterprise security and support.','containers','#13bef9','portainer/portainer-ee','latest',9443,512,2,true,'commercial','Portainer Business License','paid','https://www.portainer.io/pricing',true,'https://www.portainer.io/','https://docs.portainer.io/start/install/server/docker/linux',['/data']],
            ['Mattermost Enterprise','mattermost-enterprise','communication','Secure enterprise messaging and collaboration for private infrastructure.','message-square','#0058cc','mattermost/mattermost-enterprise-edition','latest',8065,4096,10,false,'commercial','Mattermost Enterprise License','freemium','https://mattermost.com/pricing/',false,'https://mattermost.com/','https://docs.mattermost.com/deployment-guide/server/deploy-containers.html',['/mattermost/config','/mattermost/data','/mattermost/logs']],
            ['ONLYOFFICE Docs Enterprise','onlyoffice-enterprise','business','Commercial document editing and collaboration server.','files','#ff6f3d','onlyoffice/documentserver-ee','latest',80,4096,40,false,'commercial','ONLYOFFICE Commercial License','paid','https://www.onlyoffice.com/docs-enterprise-prices.aspx',true,'https://www.onlyoffice.com/docs-enterprise.aspx','https://helpcenter.onlyoffice.com/docs/installation/docs-enterprise-install-docker.aspx',['/var/log/onlyoffice','/var/www/onlyoffice/Data','/var/lib/onlyoffice','/var/lib/postgresql']],
        ];

        $environmentFactory = app(CatalogEnvironmentFactory::class);
        foreach ($catalog as [$name,$slug,$category,$description,$icon,$accent,$image,$tag,$port,$memory,$disk,$featured,$licenseType,$licenseName,$pricingModel,$pricingUrl,$requiresLicense,$websiteUrl,$documentationUrl,$volumePaths]) {
            $application = Application::firstOrNew(['slug' => $slug]);
            $application->fill([
                'category_id' => $categories[$category]->id,
                'name' => $name,
                'description' => $description,
                'icon' => $icon,
                'accent' => $accent,
                'website_url' => $websiteUrl,
                'documentation_url' => $documentationUrl,
                'license_type' => $licenseType,
                'license_name' => $licenseName,
                'pricing_model' => $pricingModel,
                'pricing_url' => $pricingUrl,
                'requires_license' => $requiresLicense,
                'docker_image' => $image,
                'default_tag' => $tag,
                'default_port' => $port,
                'minimum_memory_mb' => $memory,
                'minimum_disk_gb' => $disk,
                'featured' => $featured,
                'active' => true,
                'verified' => true,
            ]);
            if (! $application->exists) {
                $application->install_count = $featured ? 100 : 20;
            }
            $application->save();

            $volumes = collect($volumePaths)->values()->map(fn (string $path, int $index): array => [
                'name' => $index === 0 ? 'data' : 'data-'.($index + 1),
                'path' => $path,
            ])->all();

            $application->template()->updateOrCreate([], [
                'compose_template' => "services:\n  app:\n    image: {$image}:{$tag}\n    restart: unless-stopped\n    networks: [internal]\nnetworks:\n  internal:\n    internal: true",
                'environment_schema' => $environmentFactory->schemaFor($slug) ?? [],
                'volume_schema' => $volumes,
                'port_schema' => [['container' => $port]],
                'healthcheck' => 'container',
                'restart_policy' => 'unless-stopped',
                'installation_notes' => $requiresLicense
                    ? 'A vendor license is required and is not included with Uplary Cloud.'
                    : 'Review credentials, storage, and the production domain before deploying.',
            ]);
        }
    }
}
