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
            ['cms', 'CMS', 'panels-top-left', 2],
            ['monitoring', 'Monitoring', 'activity', 3],
            ['analytics', 'Analytics', 'chart-no-axes-combined', 4],
            ['developer-tools', 'Developer Tools', 'code-2', 5],
            ['storage', 'Storage', 'hard-drive', 6],
            ['security', 'Security', 'shield-check', 7],
            ['ai', 'AI', 'sparkles', 8],
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
            // Existing expansion apps
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

            // Free open-source CMS (Docker)
            ['Drupal','drupal','cms','Flexible open-source CMS for ambitious digital experiences.','globe','#0678be','drupal','10-apache',80,1024,5,true,'open_source','GPL-2.0','free',null,false,'https://www.drupal.org/','https://www.drupal.org/docs',['/var/www/html']],
            ['Joomla','joomla','cms','Full-featured open-source CMS for websites and intranets.','layout-template','#5091cd','joomla','5-php8.3-apache',80,512,5,true,'open_source','GPL-2.0','free',null,false,'https://www.joomla.org/','https://docs.joomla.org/',['/var/www/html']],
            ['Grav','grav','cms','Fast flat-file CMS with Twig themes and Markdown content.','file-text','#000000','getgrav/grav','latest',80,512,2,true,'open_source','MIT','free',null,false,'https://getgrav.org/','https://learn.getgrav.org/',['/var/www/html']],
            ['Directus','directus','cms','Open-source headless CMS and real-time API for any SQL database.','box','#6644ff','directus/directus','11',8055,1024,5,true,'open_source','GPL-3.0','free',null,false,'https://directus.io/','https://docs.directus.io/',['/directus/uploads','/directus/database']],
            ['Concrete CMS','concrete-cms','cms','Open-source CMS focused on ease of use and marketing teams.','brick-wall','#f57e25','ghcr.io/concrete5-community/docker5','9',80,1024,5,false,'open_source','MIT','free',null,false,'https://www.concretecms.com/','https://documentation.concretecms.org/',['/var/www/html']],
            ['Backdrop CMS','backdrop','cms','Straightforward Drupal fork for small-to-medium organizations.','panels-top-left','#cf5a3b','backdrop','1-apache',80,512,5,false,'open_source','GPL-2.0','free',null,false,'https://backdropcms.org/','https://docs.backdropcms.org/',['/var/www/html']],
            ['TYPO3','typo3','cms','Enterprise-grade open-source CMS with strong multi-site support.','building-2','#ff8700','martinhelmich/typo3','12.4',80,1024,5,false,'open_source','GPL-2.0','free',null,false,'https://typo3.org/','https://docs.typo3.org/',['/var/www/html']],
            ['PrestaShop','prestashop','cms','Open-source e-commerce CMS for online stores.','shopping-bag','#df0067','prestashop/prestashop','8.1',80,1024,10,true,'open_source','OSL-3.0','free',null,false,'https://www.prestashop.com/','https://devdocs.prestashop-project.org/',['/var/www/html']],
            ['Bludit','bludit','cms','Simple flat-file CMS — no database required.','notebook-pen','#000000','bludit/docker','latest',80,256,1,false,'open_source','MIT','free',null,false,'https://www.bludit.com/','https://docs.bludit.com/',['/usr/share/nginx/html/bl-content']],
            ['DokuWiki','dokuwiki','cms','File-based wiki CMS ideal for documentation teams.','book-marked','#2b73b7','lscr.io/linuxserver/dokuwiki','latest',80,512,2,false,'open_source','GPL-2.0','free',null,false,'https://www.dokuwiki.org/','https://www.dokuwiki.org/install',['/config']],
            ['Cockpit CMS','cockpit','cms','API-first headless CMS with a lightweight admin UI.','component','#d83333','agentejo/cockpit','latest',80,512,2,false,'open_source','MIT','free',null,false,'https://getcockpit.com/','https://getcockpit.com/documentation',['/var/www/html/storage']],
            ['Wiki.js','wikijs','cms','Modern wiki CMS with Markdown, access control, and search.','library','#1976d2','ghcr.io/requarks/wiki','2',3000,1024,5,false,'open_source','AGPL-3.0','free',null,false,'https://js.wiki/','https://docs.requarks.io/',['/wiki/data']],
            ['Moodle','moodle','cms','Open-source learning management system and course CMS.','graduation-cap','#f98012','moodlehq/moodle-php-apache','8.3',80,1024,10,false,'open_source','GPL-3.0','free',null,false,'https://moodle.org/','https://docs.moodle.org/',['/var/www/html']],
            ['Flarum','flarum','cms','Elegant open-source forum platform for community publishing.','messages-square','#e7672e','mondedie/flarum','latest',8888,512,2,false,'open_source','MIT','free',null,false,'https://flarum.org/','https://docs.flarum.org/',['/flarum/public','/flarum/storage']],

            // Paid / freemium CMS & docs (installable; vendor license separate)
            ['Craft CMS','craft-cms','cms','Flexible CMS for developers — commercial license required for production.','drafting-compass','#e5422b','craftcms/nginx','8.3',8080,1024,5,false,'commercial','Craft CMS Commercial License','paid','https://craftcms.com/pricing',true,'https://craftcms.com/','https://craftcms.com/docs/5.x/install.html',['/app']],
            ['ONLYOFFICE Docs Community','onlyoffice-community','business','Self-hosted document editors under the free community edition.','file-pen','#ffa627','onlyoffice/documentserver','latest',80,4096,20,false,'open_source','AGPL-3.0','free',null,false,'https://www.onlyoffice.com/','https://helpcenter.onlyoffice.com/installation/docs-community-install-docker.aspx',['/var/log/onlyoffice','/var/www/onlyoffice/Data','/var/lib/onlyoffice','/var/lib/postgresql']],

            // Other useful free OSS
            ['MinIO','minio','storage','S3-compatible high-performance object storage.','hard-drive','#c72e49','minio/minio','latest',9001,1024,20,true,'open_source','AGPL-3.0','free',null,false,'https://min.io/','https://min.io/docs/minio/container/index.html',['/data']],
            ['NocoDB','nocodb','databases','Open-source Airtable alternative on top of your SQL databases.','table','#249fca','nocodb/nocodb','latest',8080,1024,5,false,'open_source','AGPL-3.0','free',null,false,'https://nocodb.com/','https://docs.nocodb.com/',['/usr/app/data']],
            ['FreshRSS','freshrss','communication','Self-hosted RSS aggregator for following publishers and blogs.','rss','#0062be','freshrss/freshrss','latest',80,256,1,false,'open_source','AGPL-3.0','free',null,false,'https://freshrss.org/','https://freshrss.github.io/FreshRSS/en/admins/02_Installation.html',['/var/www/FreshRSS/data']],
            ['Keycloak','keycloak','security','Open-source identity and access management.','key-round','#4d4d4d','quay.io/keycloak/keycloak','26.0',8080,1024,2,false,'open_source','Apache-2.0','free',null,false,'https://www.keycloak.org/','https://www.keycloak.org/server/containers',['/opt/keycloak/data']],
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
                    ? 'A vendor license is required and is not included with Uplary Cloud. You can still deploy the image; activate the license with the vendor after install.'
                    : 'Review credentials, storage, and the production domain before deploying.',
            ]);
        }
    }
}
