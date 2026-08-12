<?php

namespace Database\Seeders;

use App\Models\ApplicationDeployment;
use App\Models\Domain;
use Illuminate\Database\Seeder;

class Phase6DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['Workflow Automation','automation.example.com',68],['Customer Portal','portal.example.com',51]] as [$name,$hostname,$days]) {
            $deployment=ApplicationDeployment::with('server')->where('name',$name)->first();
            if(!$deployment)continue;
            $deployment->server->update(['proxy_status'=>'running','proxy_version'=>config('networking.proxy_image'),'proxy_network'=>config('networking.proxy_network'),'proxy_installed_at'=>now()->subDays(18)]);
            Domain::updateOrCreate(['hostname'=>$hostname],['tenant_id'=>$deployment->tenant_id,'application_deployment_id'=>$deployment->id,'server_id'=>$deployment->server_id,'created_by'=>$deployment->created_by,'force_https'=>true,'ssl_enabled'=>true,'auto_renew'=>true,'status'=>'active','dns_status'=>'verified','dns_record_type'=>'A','expected_value'=>$deployment->server->ip_address,'resolved_values'=>[$deployment->server->ip_address],'last_dns_check_at'=>now()->subMinutes(4),'dns_verified_at'=>now()->subDays(18),'proxy_status'=>'configured','proxy_configured_at'=>now()->subDays(18),'ssl_status'=>'valid','certificate_provider'=>"Let's Encrypt",'certificate_serial'=>strtoupper(substr(hash('sha256',$hostname),0,24)),'certificate_issued_at'=>now()->subDays(18),'certificate_expires_at'=>now()->addDays($days),'last_renewal_at'=>now()->subDays(18)]);
        }
    }
}
