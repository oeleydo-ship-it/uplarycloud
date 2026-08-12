<?php

namespace Database\Seeders;

use App\Enums\MembershipRole;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Server;
use App\Models\DockerContainer;
use App\Models\DockerImage;
use App\Models\DockerNetwork;
use App\Models\DockerVolume;
use App\Models\Application;
use App\Models\ApplicationCategory;
use App\Models\ApplicationDeployment;
use App\Models\BuildPack;
use App\Services\Applications\CatalogEnvironmentFactory;
use App\Services\Deployments\DeploymentService;
use App\Services\Deployments\WebApplicationDeploymentService;
use App\Models\Tenant;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Demo Owner',
            'email' => 'demo@example.com',
            'email_verified_at' => now(),
            'password' => 'password',
            'is_super_admin' => true,
        ]);

        $tenant = Tenant::create(['name' => 'Demo Workspace', 'slug' => 'demo-workspace']);
        $tenant->users()->attach($user, ['role' => MembershipRole::Owner->value]);

        foreach ([
            'name' => env('APP_NAME', 'Uplary Cloud'),
            'short_name' => 'UP',
            'tagline' => 'Deploy confidently. Operate clearly.',
            'primary_color' => '#6C4CF5',
            'secondary_color' => '#17152B',
            'company_name' => env('APP_NAME', 'Uplary Cloud'),
            'support_email' => 'support@example.com',
        ] as $key => $value) {
            Setting::create(['tenant_id' => $tenant->id, 'group' => 'branding', 'key' => $key, 'value' => $value]);
        }

        foreach ([
            ['deployment.completed', 'Customer Portal v2.4.1 deployed successfully'],
            ['server.connected', 'Production Server health check passed'],
            ['backup.completed', 'Database backup completed'],
            ['domain.verified', 'Domain DNS records verified'],
        ] as $index => [$action, $description]) {
            ActivityLog::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'action' => $action,
                'description' => $description,
                'status' => 'success',
                'created_at' => now()->subMinutes(($index + 1) * 12),
            ]);
        }

        foreach ([
            ['Production Server', 'hetzner', '203.0.113.10', 'Frankfurt, Germany', 'ubuntu-24.04', 4, 8192, 160, 'online'],
            ['Staging Server', 'digitalocean', '203.0.113.21', 'Dubai, UAE', 'ubuntu-22.04', 2, 4096, 80, 'online'],
            ['Database Server', 'custom', '203.0.113.32', 'London, UK', 'debian-12', 8, 16384, 320, 'offline'],
        ] as [$name, $provider, $ip, $location, $os, $cpu, $memory, $disk, $status]) {
            $server = Server::create([
                'tenant_id' => $tenant->id, 'name' => $name, 'provider' => $provider,
                'ip_address' => $ip, 'location' => $location, 'operating_system' => $os,
                'status' => $status, 'ssh_port' => 22, 'ssh_username' => 'root',
                'authentication_method' => 'ssh_key', 'cpu_cores' => $cpu,
                'memory_mb' => $memory, 'disk_gb' => $disk, 'docker_version' => '28.3.3',
                'docker_compose_version' => '2.38.2', 'last_seen_at' => $status === 'online' ? now() : now()->subHours(2),
                'provisioned_at' => now()->subDays(24),
            ]);
            $server->credential()->create(['private_key' => 'demo-encrypted-key-'.$server->uuid]);
            foreach (range(1, 24) as $hour) {
                $server->metrics()->create(['cpu_percent' => 18 + (($hour * $server->id * 7) % 48), 'memory_percent' => 32 + (($hour * $server->id * 5) % 40), 'disk_percent' => 42 + ($server->id * 6), 'load_average' => 0.5 + (($hour % 8) / 10), 'recorded_at' => now()->subHours(24 - $hour)]);
            }
        }

        $repositories = ['nginx','postgres','redis','n8nio/n8n','wordpress','ghcr.io/umami-software/umami'];
        foreach (Server::where('tenant_id', $tenant->id)->get() as $serverIndex => $server) {
            $network = DockerNetwork::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'docker_id'=>substr(hash('sha256','network'.$server->id),0,12),'name'=>$serverIndex===0?'platform-proxy':'app-'.$server->id.'-internal','driver'=>'bridge','internal'=>$serverIndex!==0,'attachable'=>true,'subnet'=>'172.'.(20+$serverIndex).'.0.0/16','gateway'=>'172.'.(20+$serverIndex).'.0.1']);
            foreach (array_slice($repositories,0,4+$serverIndex) as $imageIndex => $repository) {
                $image = DockerImage::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'docker_id'=>'sha256:'.substr(hash('sha256',$repository.$server->id),0,12),'repository'=>$repository,'tag'=>$imageIndex%2?'latest':'alpine','size_bytes'=>(120+$imageIndex*85)*1048576,'used_by_count'=>$imageIndex<3?1:0,'pulled_at'=>now()->subDays($imageIndex+1),'update_available'=>$imageIndex===1]);
                if ($imageIndex < 3) {
                    $container = DockerContainer::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'docker_id'=>substr(hash('sha256','container'.$server->id.$imageIndex),0,12),'name'=>str($repository)->afterLast('/')->replace(':','-').'-'.$server->id,'image'=>$repository.':'.$image->tag,'status'=>$server->status->value==='online'?'running':'stopped','health'=>$imageIndex===2?'unhealthy':'healthy','ports'=>$imageIndex===0?[['private'=>80,'public'=>80+$serverIndex]]:[],'cpu_percent'=>8+$imageIndex*11+$serverIndex,'memory_usage_mb'=>128+$imageIndex*190,'memory_limit_mb'=>[512, null, 2048][$imageIndex] ?? null,'restart_count'=>$imageIndex===2?2:0,'started_at'=>now()->subDays(4)]);
                    $container->networks()->attach($network,['ip_address'=>'172.'.(20+$serverIndex).'.0.'.(10+$imageIndex)]);
                    if ($imageIndex > 0) { $volume=DockerVolume::create(['tenant_id'=>$tenant->id,'server_id'=>$server->id,'docker_name'=>'app-'.$server->id.'-'.$imageIndex.'-data','name'=>ucfirst(str($repository)->afterLast('/')).' Data','size_bytes'=>(2+$imageIndex*3)*1073741824,'mountpoint'=>'/var/lib/docker/volumes/app-'.$server->id.'-'.$imageIndex.'-data','backed_up_at'=>now()->subHours(8+$imageIndex)]);$container->volumes()->attach($volume,['mount_path'=>'/data']); }
                }
            }
        }

        $categories=[];
        foreach ([['Automation','workflow',1],['CMS','panels-top-left',2],['Monitoring','activity',3],['Analytics','chart-no-axes-combined',4],['Developer Tools','code-2',5],['Storage','hard-drive',6],['Security','shield-check',7],['AI','sparkles',8],['Databases','database',9],['Communication','messages-square',10],['Media','play-square',11],['Business','briefcase-business',12]] as [$name,$icon,$position]) {
            $category=ApplicationCategory::create(['name'=>$name,'slug'=>(string) str($name)->slug(),'icon'=>$icon,'position'=>$position]); $categories[(string) $category->slug]=$category;
        }
        $catalog=[
            ['n8n','n8n','automation','Workflow automation with hundreds of integrations.','workflow','#ea4b71','n8nio/n8n','latest',5678,512,1,true],
            ['Uptime Kuma','uptime-kuma','monitoring','Beautiful self-hosted uptime monitoring.','heart-pulse','#34b27b','louislam/uptime-kuma','1',3001,256,1,true],
            ['WordPress','wordpress','cms','The world’s most popular publishing platform.','panels-top-left','#287eb3','wordpress','latest',80,512,5,true],
            ['Ghost','ghost','cms','Modern publishing for professional creators.','circle','#202129','ghost','5-alpine',2368,512,2,true],
            ['Gitea','gitea','developer-tools','Lightweight self-hosted Git collaboration.','git-branch','#62a944','gitea/gitea','latest',3000,512,5,true],
            ['Nextcloud','nextcloud','storage','Private file sync, sharing, and collaboration.','cloud','#1689d4','nextcloud','apache',80,1024,10,true],
            ['Umami','umami','analytics','Privacy-focused, open-source web analytics.','chart-no-axes-combined','#6c4cf5','ghcr.io/umami-software/umami','postgresql-latest',3000,512,2,false],
            ['Vaultwarden','vaultwarden','security','Lightweight Bitwarden-compatible password server.','shield-check','#356ad3','vaultwarden/server','latest',80,256,1,false],
            ['Metabase','metabase','analytics','Explore data and share business intelligence.','bar-chart-3','#4fa5d8','metabase/metabase','latest',3000,1024,2,false],
            ['BookStack','bookstack','cms','Simple, self-hosted documentation platform.','book-open','#ef8a35','lscr.io/linuxserver/bookstack','latest',80,512,2,false],
            ['SFTPGo','sftpgo','storage','Secure file transfer and storage gateway.','folder-sync','#4d83d6','drakkan/sftpgo','latest',8080,512,5,false],
            ['Open WebUI','open-webui','ai','Feature-rich interface for local AI models.','sparkles','#21242c','ghcr.io/open-webui/open-webui','main',8080,2048,10,true],
            ['PostgreSQL','postgresql','databases','Reliable open-source relational database.','database','#336791','postgres','16-alpine',5432,512,5,false],
            ['MySQL','mysql','databases','Popular production relational database.','database','#ec8e22','mysql','8.4',3306,1024,5,false],
            ['Redis','redis','databases','Fast in-memory data store and cache.','layers','#dc3d32','redis','7-alpine',6379,256,1,false],
            ['Meilisearch','meilisearch','developer-tools','Lightning-fast application search engine.','search','#ff5c7c','getmeili/meilisearch','latest',7700,512,2,false],
        ];
        $environmentFactory = app(CatalogEnvironmentFactory::class);
        foreach ($catalog as [$name,$slug,$category,$description,$icon,$accent,$image,$tag,$port,$memory,$disk,$featured]) {
            $app=Application::create(['category_id'=>$categories[$category]->id,'name'=>$name,'slug'=>$slug,'description'=>$description,'icon'=>$icon,'accent'=>$accent,'website_url'=>'https://'.$slug.'.com','documentation_url'=>'https://docs.'.$slug.'.com','docker_image'=>$image,'default_tag'=>$tag,'default_port'=>$port,'minimum_memory_mb'=>$memory,'minimum_disk_gb'=>$disk,'featured'=>$featured,'install_count'=>$featured?120-rand(1,30):rand(2,50)]);
            $environment = $environmentFactory->schemaFor($slug) ?? [['key'=>'TZ','value'=>'Asia/Dubai','description'=>'Application timezone','secret'=>false]];
            $app->template()->create(['compose_template'=>"services:\n  app:\n    image: {$image}:{$tag}\n    restart: unless-stopped\n    networks: [internal]\nnetworks:\n  internal:\n    internal: true",'environment_schema'=>$environment,'volume_schema'=>[['name'=>'data','path'=>'/data']],'port_schema'=>[['container'=>$port]],'healthcheck'=>'container','restart_policy'=>'unless-stopped','installation_notes'=>'Review credentials and set a production domain before deploying.']);
        }
        $this->call(ApplicationCatalogExpansionSeeder::class);

        $this->call(BuildPackSeeder::class);
        $n8n=Application::where('slug','n8n')->firstOrFail(); $production=Server::where('tenant_id',$tenant->id)->where('name','Production Server')->firstOrFail();
        $demo=ApplicationDeployment::create(['tenant_id'=>$tenant->id,'application_id'=>$n8n->id,'server_id'=>$production->id,'created_by'=>$user->id,'name'=>'Workflow Automation','slug'=>'workflow-automation','deployment_type'=>'marketplace','docker_image'=>$n8n->docker_image,'docker_tag'=>$n8n->default_tag,'container_port'=>5678,'domain'=>'automation.example.com','cpu_limit'=>.5,'memory_limit_mb'=>512,'disk_limit_gb'=>5,'auto_start'=>true,'backup_enabled'=>true,'restart_policy'=>'unless-stopped','status'=>'running','progress'=>100,'current_stage'=>'complete','started_at'=>now()->subMinutes(9),'completed_at'=>now()->subMinutes(7),'deployed_at'=>now()->subMinutes(7)]);
        foreach (array_values(DeploymentService::STAGES) as $position=>$name) { $keys=array_keys(DeploymentService::STAGES); $demo->steps()->create(['key'=>$keys[$position],'name'=>$name,'position'=>$position+1,'status'=>'completed','started_at'=>now()->subMinutes(9)->addSeconds($position*7),'completed_at'=>now()->subMinutes(9)->addSeconds($position*7+5)]); }
        foreach ([['TZ','Asia/Dubai',false,'Application timezone'],['N8N_HOST','automation.example.com',false,'Public hostname'],['N8N_BASIC_AUTH_USER','admin',false,'Administrator username'],['N8N_BASIC_AUTH_PASSWORD','demo-secret',true,'Administrator password']] as [$key,$value,$secret,$description]) $demo->environmentVariables()->create(compact('key','value','secret','description'));
        foreach ([['info','Starting deployment of Workflow Automation.'],['success','Server connection established.'],['success','Docker image pulled successfully.'],['success','Persistent data volume created.'],['success','Encrypted environment variables prepared.'],['success','Application container started.'],['success','Health check passed.'],['success','Deployment completed successfully.']] as $index=>[$level,$message]) $demo->logs()->create(['level'=>$level,'message'=>$message,'occurred_at'=>now()->subMinutes(9)->addSeconds($index*12)]);
        $demo->releases()->create(['version'=>'v20260810.1','image'=>$n8n->docker_image,'image_tag'=>'1.105.4','status'=>'successful','is_current'=>false,'configuration'=>['memory_limit_mb'=>512],'deployed_at'=>now()->subDay()]);
        $demo->releases()->create(['version'=>'v20260811.1','image'=>$n8n->docker_image,'image_tag'=>$n8n->default_tag,'status'=>'successful','is_current'=>true,'configuration'=>['memory_limit_mb'=>512],'deployed_at'=>now()->subMinutes(7)]);

        $laravelPack=BuildPack::where('slug','laravel')->firstOrFail();
        $portal=ApplicationDeployment::create(['tenant_id'=>$tenant->id,'build_pack_id'=>$laravelPack->id,'server_id'=>$production->id,'created_by'=>$user->id,'name'=>'Customer Portal','slug'=>'customer-portal','deployment_type'=>'git','framework'=>'laravel','description'=>'Laravel customer workspace deployed from Git.','docker_image'=>'platform/customer-portal','docker_tag'=>'latest','container_port'=>8000,'domain'=>'portal.example.com','cpu_limit'=>.75,'memory_limit_mb'=>768,'disk_limit_gb'=>5,'restart_policy'=>'unless-stopped','git_provider'=>'github','repository_url'=>'https://github.com/uplary/customer-portal.git','branch'=>'main','commit_hash'=>'c1d922a3b51792ff6a26ac708425b5a2e54aa602','runtime_version'=>'8.5','root_directory'=>'/','package_manager'=>'composer','install_command'=>'composer install --no-dev --optimize-autoloader','build_command'=>'php artisan config:cache','start_command'=>'php artisan serve','database_engine'=>'postgresql','enable_redis'=>true,'enable_queue'=>true,'enable_scheduler'=>true,'auto_deploy'=>true,'webhook_secret'=>'demo-webhook-secret','build_status'=>'successful','status'=>'running','progress'=>100,'current_stage'=>'complete','started_at'=>now()->subMinutes(35),'completed_at'=>now()->subMinutes(31),'deployed_at'=>now()->subMinutes(31)]);
        foreach (array_values(WebApplicationDeploymentService::STAGES) as $position=>$name) { $keys=array_keys(WebApplicationDeploymentService::STAGES); $portal->steps()->create(['key'=>$keys[$position],'name'=>$name,'position'=>$position+1,'status'=>'completed','started_at'=>now()->subMinutes(35)->addSeconds($position*18),'completed_at'=>now()->subMinutes(35)->addSeconds($position*18+12)]); }
        foreach ([['info','Starting Laravel build from GitHub.'],['success','Repository cloned at c1d922a.'],['success','Laravel build pack selected.'],['success','Composer dependencies installed.'],['success','Production Docker image built.'],['success','PostgreSQL and Redis services started.'],['success','Database migrations completed.'],['success','Queue and scheduler sidecars started.'],['success','Application health check passed.'],['success','Deployment completed successfully.']] as $index=>[$level,$message]) $portal->logs()->create(['level'=>$level,'message'=>$message,'occurred_at'=>now()->subMinutes(35)->addSeconds($index*20)]);
        $portal->environmentVariables()->create(['key'=>'APP_ENV','value'=>'production','secret'=>false,'description'=>'Laravel environment']);
        $portal->releases()->create(['version'=>'v20260811.2','image'=>$portal->docker_image,'image_tag'=>'latest','commit'=>$portal->commit_hash,'status'=>'successful','is_current'=>true,'configuration'=>['memory_limit_mb'=>768],'deployed_at'=>now()->subMinutes(31)]);

        DockerContainer::query()
            ->where('tenant_id', $tenant->id)
            ->where('server_id', $production->id)
            ->where('image', 'like', 'n8nio/n8n%')
            ->update(['application_deployment_id' => $demo->id, 'name' => 'n8n', 'ports' => [['private' => 5678, 'public' => 5678]], 'memory_usage_mb' => 426, 'memory_limit_mb' => 2048, 'cpu_percent' => 4.2, 'started_at' => now()->subDays(2)->subHours(5)]);

        DockerContainer::query()
            ->where('tenant_id', $tenant->id)
            ->where('server_id', $production->id)
            ->where('image', 'like', 'platform/customer-portal%')
            ->update(['application_deployment_id' => $portal->id, 'name' => 'customer-portal', 'ports' => [['private' => 8000, 'public' => 8000]], 'memory_usage_mb' => 512, 'memory_limit_mb' => 768, 'cpu_percent' => 6.8, 'started_at' => now()->subHours(8)]);

        $this->call(Phase6DemoSeeder::class);
        $this->call(Phase7DemoSeeder::class);
        $this->call(Phase8DemoSeeder::class);
        $this->call(Phase9DemoSeeder::class);
        $this->call(Phase11DemoSeeder::class);
        $this->call(Phase13DemoSeeder::class);
    }
}
