<?php

namespace App\Http\Controllers;
use App\Jobs\DockerResourceActionJob;use App\Models\DockerNetwork;use App\Support\TenantContext;use Illuminate\Http\RedirectResponse;use Illuminate\Http\Request;use Illuminate\View\View;
class DockerNetworkController extends Controller
{public function index(TenantContext $c):View{return view('docker.networks',['networks'=>DockerNetwork::where('tenant_id',$c->id())->withCount('containers')->with(['server'=>fn($q)=>$q->withTrashed()])->latest()->paginate(12)]);}public function destroy(Request $r,DockerNetwork $network,TenantContext $c):RedirectResponse{abort_unless($network->tenant_id===$c->id(),404);$this->authorize('operate',$network->server);if($network->containers()->exists())return back()->withErrors(['network'=>'Disconnect all containers before removing this network.']);DockerResourceActionJob::dispatch('network',$network->id,'remove',$c->id(),$r->user()->id);return back()->with('success','Network removal queued.');}}
