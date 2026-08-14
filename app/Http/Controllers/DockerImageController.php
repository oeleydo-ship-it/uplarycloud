<?php

namespace App\Http\Controllers;

use App\Jobs\CheckImageUpdatesJob;
use App\Jobs\DockerResourceActionJob;
use App\Jobs\UpdateDockerImageJob;
use App\Models\DockerImage;
use App\Models\DockerContainer;
use App\Models\Server;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DockerImageController extends Controller
{
    public function index(Request $request,TenantContext $context):View
    {
        $tenantId=$context->id();
        $liveServers=Server::liveIdQuery($tenantId);
        $base=DockerImage::query()->where('tenant_id',$tenantId)->whereIn('server_id',$liveServers);
        $containers=DockerContainer::query()->where('tenant_id',$tenantId)->whereIn('server_id',$liveServers)->with(['deployment'=>fn($q)=>$q->withTrashed(),'deployment.application','volumes'])->get();
        $allImages=(clone $base)->get();
        $usage=$allImages->mapWithKeys(fn(DockerImage $image)=>[$image->id=>$image->matchingContainers($containers)->count()]);
        $unusedIds=$usage->filter(fn(int $count)=>$count===0)->keys();
        $unusedBytes=$allImages->whereIn('id',$unusedIds)->sum('size_bytes');

        $query=(clone $base)->with(['server'=>fn($q)=>$q->withTrashed()])
            ->when($request->filled('search'),fn($q)=>$q->where(fn($x)=>$x->where('repository','like','%'.$request->search.'%')->orWhere('docker_id','like','%'.$request->search.'%')))
            ->when($request->filled('server'),fn($q)=>$q->where('server_id',$request->integer('server')))
            ->when($request->filled('repository'),fn($q)=>$q->where('repository',$request->string('repository')))
            ->when($request->filled('tag'),fn($q)=>$q->where('tag',$request->string('tag')))
            ->when($request->status==='outdated',fn($q)=>$q->where('update_available',true))
            ->when($request->status==='current',fn($q)=>$q->where('update_available',false))
            ->when($request->status==='unused',fn($q)=>$q->whereIn('id',$unusedIds))
            ->when($request->status==='in_use',fn($q)=>$q->whereNotIn('id',$unusedIds))
            ->when(!$request->boolean('show_unused') && !$request->filled('status'),fn($q)=>$q->whereNotIn('id',$unusedIds))
            ->latest('pulled_at');

        $images=$query->paginate(10)->withQueryString();
        $images->getCollection()->each(function(DockerImage $image)use($containers):void{$image->setRelation('containers',$image->matchingContainers($containers));});
        $selected=$request->filled('selected')
            ? DockerImage::where('tenant_id',$tenantId)->where('uuid',$request->string('selected'))->with(['server'=>fn($q)=>$q->withTrashed()])->first()
            : $images->first();
        if($selected)$selected->setRelation('containers',$selected->matchingContainers($containers));

        return view('docker.images',[
            'images'=>$images,'selectedImage'=>$selected,
            'servers'=>Server::where('tenant_id',$tenantId)->orderBy('name')->get(),
            'repositories'=>(clone $base)->distinct()->orderBy('repository')->pluck('repository'),
            'tags'=>(clone $base)->distinct()->orderBy('tag')->pluck('tag'),
            'counts'=>['all'=>$allImages->count(),'size'=>$allImages->sum('size_bytes'),'unused'=>$unusedIds->count(),'unused_size'=>$unusedBytes,'updates'=>$allImages->where('update_available',true)->count()],
        ]);
    }
    public function pull(Request $request,TenantContext $context):RedirectResponse
    {
        $data=$request->validate(['server_id'=>['required','integer'],'repository'=>['required','string','max:255','regex:/^[a-zA-Z0-9._\/-]+$/'],'tag'=>['required','string','max:128','regex:/^[a-zA-Z0-9._-]+$/']]);
        $server=Server::where('tenant_id',$context->id())->findOrFail($data['server_id']);$this->authorize('operate',$server);
        $image=DockerImage::firstOrCreate(['tenant_id'=>$context->id(),'server_id'=>$server->id,'repository'=>$data['repository'],'tag'=>$data['tag']],['status'=>'pulling','pulled_at'=>now()]);
        DockerResourceActionJob::dispatch('image',$image->id,'pull',$context->id(),$request->user()->id);
        return back()->with('success','Image pull queued.');
    }
    public function cleanup(Request $request,TenantContext $context):RedirectResponse
    {
        $containers=DockerContainer::where('tenant_id',$context->id())->get();$queued=0;
        DockerImage::where('tenant_id',$context->id())->with('server')->get()->each(function(DockerImage $image)use($containers,$context,$request,&$queued):void{
            if($image->used_by_count>0||$image->matchingContainers($containers)->isNotEmpty())return;$this->authorize('operate',$image->server);DockerResourceActionJob::dispatch('image',$image->id,'remove',$context->id(),$request->user()->id);$queued++;
        });
        return back()->with('success',$queued.' unused image removal'.($queued===1?'':'s').' queued.');
    }
    public function check(TenantContext $context):RedirectResponse{CheckImageUpdatesJob::dispatch($context->id());return back()->with('success','Image update scan queued.');}
    public function action(Request $request,DockerImage $image,TenantContext $context):RedirectResponse
    {
        abort_unless($image->tenant_id===$context->id(),404);$this->authorize('operate',$image->server);$action=$request->validate(['action'=>['required','in:pull,remove,update,backup_update,ignore']])['action'];
        $containers=DockerContainer::where('tenant_id',$context->id())->get();
        if($action==='remove'&&($image->used_by_count>0||$image->matchingContainers($containers)->isNotEmpty()))return back()->withErrors(['image'=>'This image is used by active containers.']);
        if($action==='ignore'){$image->update(['update_available'=>false,'metadata'=>array_merge($image->metadata??[],['ignored_at'=>now()->toIso8601String()])]);return back()->with('success','This update was ignored.');}
        if(in_array($action,['update','backup_update'],true)){UpdateDockerImageJob::dispatch($image->id,$context->id(),$request->user()->id,$action==='backup_update');return back()->with('success',$action==='backup_update'?'Safety backup and update queued.':'Image update queued.');}
        DockerResourceActionJob::dispatch('image',$image->id,$action,$context->id(),$request->user()->id);return back()->with('success','Image action queued.');
    }
}
