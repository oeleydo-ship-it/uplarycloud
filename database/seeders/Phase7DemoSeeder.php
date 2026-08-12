<?php

namespace Database\Seeders;

use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\ApplicationDeployment;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\BackupSchedule;
use App\Models\DockerContainer;
use App\Models\OperationalLog;
use App\Models\Server;
use Illuminate\Database\Seeder;

class Phase7DemoSeeder extends Seeder
{
    public function run():void
    {
        $production=Server::where('name','Production Server')->first();$staging=Server::where('name','Staging Server')->first();if(!$production)return;$tenantId=$production->tenant_id;
        foreach([$production,$staging] as $server){if(!$server)continue;for($i=47;$i>=0;$i--){$at=now()->subMinutes($i*30);$server->metrics()->firstOrCreate(['recorded_at'=>$at],['cpu_percent'=>20+(($i*7+$server->id*5)%48),'memory_percent'=>35+(($i*5+$server->id*4)%39),'disk_percent'=>43+$server->id*6,'load_average'=>.4+(($i%9)/10),'network_in_bytes'=>(50+$i*3)*1048576,'network_out_bytes'=>(30+$i*2)*1048576]);}}
        DockerContainer::where('tenant_id',$tenantId)->with('server')->get()->each(function($container){for($i=15;$i>=0;$i--)$container->metrics()->firstOrCreate(['recorded_at'=>now()->subMinutes($i*15)],['cpu_percent'=>5+(($i*9+$container->id*4)%52),'memory_usage_mb'=>128+(($i*31+$container->id*50)%700),'network_in_bytes'=>(12+$i)*1048576,'network_out_bytes'=>(7+$i)*1048576,'restart_count'=>$container->restart_count,'health'=>$container->health]);});
        $destination=BackupDestination::firstOrCreate(['tenant_id'=>$tenantId,'name'=>'Local encrypted storage'],['provider'=>'local','active'=>true,'last_verified_at'=>now()]);$portal=ApplicationDeployment::where('tenant_id',$tenantId)->where('name','Customer Portal')->first();$workflow=ApplicationDeployment::where('tenant_id',$tenantId)->where('name','Workflow Automation')->first();
        if($portal){BackupSchedule::firstOrCreate(['tenant_id'=>$tenantId,'name'=>'Daily customer portal'],['application_deployment_id'=>$portal->id,'backup_destination_id'=>$destination->id,'backup_type'=>'full','frequency'=>'daily','keep_last'=>7,'delete_after_days'=>30,'enabled'=>true,'last_run_at'=>now()->subDay(),'next_run_at'=>now()->addHours(7)]);$this->backup($portal,$destination,'Customer Portal nightly backup',now()->subHours(7),18*1048576);}
        if($workflow){BackupSchedule::firstOrCreate(['tenant_id'=>$tenantId,'name'=>'Weekly automation data'],['application_deployment_id'=>$workflow->id,'backup_destination_id'=>$destination->id,'backup_type'=>'volume','frequency'=>'weekly','keep_last'=>4,'delete_after_days'=>45,'enabled'=>true,'last_run_at'=>now()->subDays(2),'next_run_at'=>now()->addDays(5)]);$this->backup($workflow,$destination,'Workflow Automation recovery point',now()->subDays(2),42*1048576);}
        $cpuRule=AlertRule::firstOrCreate(['tenant_id'=>$tenantId,'name'=>'Production CPU above 75%'],['server_id'=>$production->id,'type'=>'cpu_high','metric'=>'cpu','threshold'=>75,'duration_minutes'=>5,'severity'=>'warning','channels'=>['dashboard'],'enabled'=>true,'last_evaluated_at'=>now()->subMinutes(2)]);$offlineRule=AlertRule::firstOrCreate(['tenant_id'=>$tenantId,'name'=>'Production server offline'],['server_id'=>$production->id,'type'=>'server_offline','duration_minutes'=>1,'severity'=>'critical','channels'=>['dashboard'],'enabled'=>true]);
        if(!$cpuRule->incidents()->exists())AlertIncident::create(['tenant_id'=>$tenantId,'alert_rule_id'=>$cpuRule->id,'status'=>'resolved','severity'=>'warning','message'=>'Production CPU above 75% triggered at 81.4','observed_value'=>81.4,'triggered_at'=>now()->subHours(5),'resolved_at'=>now()->subHours(4)]);
        if(OperationalLog::where('tenant_id',$tenantId)->count()===0){foreach([['system','info','scheduler','Scheduled monitoring collection completed'],['server','info','monitor','Production Server responded in 42 ms'],['container','warning','docker','redis-1 restart count increased to 2'],['backup','info','backup-worker','Customer Portal nightly backup completed'],['deployment','info','deployment-worker','Customer Portal release health check passed'],['application','debug','customer-portal','Queue heartbeat processed 24 jobs'],['system','error','alerts','Container health check briefly failed'],['server','info','monitor','Disk utilization remains below threshold']] as $i=>[$category,$severity,$source,$message])OperationalLog::create(['tenant_id'=>$tenantId,'server_id'=>$production->id,'category'=>$category,'severity'=>$severity,'source'=>$source,'message'=>$message,'occurred_at'=>now()->subMinutes($i*11+2)]);}
    }
    private function backup(ApplicationDeployment $deployment,BackupDestination $destination,string $name,$createdAt,int $size):void{$backup=Backup::firstOrCreate(['tenant_id'=>$deployment->tenant_id,'name'=>$name],['application_deployment_id'=>$deployment->id,'server_id'=>$deployment->server_id,'backup_destination_id'=>$destination->id,'created_by'=>$deployment->created_by,'backup_type'=>'full','status'=>'successful','size_bytes'=>$size,'checksum'=>hash('sha256',$name),'started_at'=>$createdAt->copy()->subMinutes(2),'completed_at'=>$createdAt,'expires_at'=>$createdAt->copy()->addDays(30),'created_at'=>$createdAt,'updated_at'=>$createdAt]);if(!$backup->storage_path){$directory=storage_path('app/private/backups/'.$deployment->tenant_id);if(!is_dir($directory))mkdir($directory,0750,true);$path=$directory.DIRECTORY_SEPARATOR.$backup->uuid.'.tar.gz';file_put_contents($path,gzencode(json_encode(['demo'=>true,'deployment'=>$deployment->name]),9),LOCK_EX);$backup->update(['storage_path'=>$path]);}}
}
