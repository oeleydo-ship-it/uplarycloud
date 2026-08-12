<?php
namespace App\Services\Infrastructure\Providers;
use App\Contracts\Infrastructure\CloudProviderAdapterInterface;use App\Models\ManagedServerPlan;use App\Models\ProviderConnection;use App\Models\Server;
class FakeCloudProviderAdapter implements CloudProviderAdapterInterface
{
    public function verify(ProviderConnection $connection):array{return['success'=>true,'account'=>$connection->account_id?:'demo-account','regions'=>['fra1','nyc3','dub1']];}
    public function create(Server $server,ManagedServerPlan $plan,array $options):array{return['resource_id'=>'managed-'.strtolower(str_replace('-','',substr($server->uuid,0,13))),'ip_address'=>'198.51.100.'.(20+($server->id%180)),'status'=>'running','region'=>$options['region'],'image'=>$options['image']];}
    public function status(Server $server):array{return['resource_id'=>$server->provider_resource_id,'status'=>'running','ip_address'=>$server->ip_address];}
    public function restart(Server $server):array{return['resource_id'=>$server->provider_resource_id,'status'=>'restarting'];}
    public function resize(Server $server,ManagedServerPlan $plan):array{return['resource_id'=>$server->provider_resource_id,'status'=>'resizing','plan'=>$plan->provider_plan_id];}
    public function rebuild(Server $server,string $image):array{return['resource_id'=>$server->provider_resource_id,'status'=>'rebuilding','image'=>$image];}
    public function destroy(Server $server):array{return['resource_id'=>$server->provider_resource_id,'status'=>'deleted'];}
}
