<?php

namespace Database\Seeders;

use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class Phase13DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant=Tenant::first(); $owner=$tenant?->users()->wherePivot('role','owner')->first(); if(!$tenant||!$owner)return;
        $catalog=[['CI / CD Deployment',['servers:read','servers:write','deployments:write'],'production','active',now()->subMinutes(18)],['Monitoring Integration',['monitoring:read'],'production','active',now()->subDay()],['Backup Automation',['backups:read','backups:write'],'production','active',now()->subDays(2)],['Development Access',['servers:read'],'staging','active',now()->subDays(3)],['Third-party Integration',['applications:read'],'production','expired',now()->subWeek()],['Legacy System Access',['servers:read'],'production','revoked',now()->subMonth()],['Read Only Token',['servers:read','applications:read','deployments:read'],'staging','active',now()->subHours(4)],['Webhook Integration',['deployments:write'],'production','expired',now()->subDays(8)]];
        foreach($catalog as $index=>[$name,$abilities,$environment,$status,$lastUsed]) PersonalAccessToken::firstOrCreate(['tokenable_type'=>get_class($owner),'tokenable_id'=>$owner->id,'tenant_id'=>$tenant->id,'name'=>$name],['token'=>hash('sha256','demo-token-'.$tenant->id.'-'.$index),'abilities'=>$abilities,'environment'=>$environment,'last_used_at'=>$lastUsed,'expires_at'=>$status==='expired'?now()->subDays(2):now()->addMonths(3),'revoked_at'=>$status==='revoked'?now()->subDays(5):null]);
    }
}
