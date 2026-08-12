<?php
namespace App\Contracts\Infrastructure;
use App\Models\ManagedServerPlan;use App\Models\ProviderConnection;use App\Models\Server;
interface CloudProviderAdapterInterface
{
    public function verify(ProviderConnection $connection):array;
    public function create(Server $server,ManagedServerPlan $plan,array $options):array;
    public function status(Server $server):array;
    public function restart(Server $server):array;
    public function resize(Server $server,ManagedServerPlan $plan):array;
    public function rebuild(Server $server,string $image):array;
    public function destroy(Server $server):array;
}
