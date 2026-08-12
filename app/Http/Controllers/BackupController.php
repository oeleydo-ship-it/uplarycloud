<?php
namespace App\Http\Controllers;
use App\Jobs\CreateBackupJob;use App\Jobs\RestoreBackupJob;use App\Models\ApplicationDeployment;use App\Models\Backup;use App\Models\BackupDestination;use App\Models\BackupSchedule;use App\Models\Server;use App\Services\Operations\BackupService;use App\Support\TenantContext;use Illuminate\Http\RedirectResponse;use Illuminate\Http\Request;use Illuminate\Validation\Rule;use Illuminate\View\View;use Symfony\Component\HttpFoundation\BinaryFileResponse;
class BackupController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $tenantId = $context->id();
        $base = Backup::query()->where('tenant_id', $tenantId);

        $stats = [
            'successful' => (clone $base)->where('status', 'successful')->count(),
            'running' => (clone $base)->whereIn('status', ['pending', 'running'])->count(),
            'size' => (clone $base)->sum('size_bytes'),
            'scheduled' => BackupSchedule::where('tenant_id', $tenantId)->where('enabled', true)->count(),
        ];

        $backups = (clone $base)
            ->with([
                'deployment' => fn ($q) => $q->withTrashed(),
                'deployment.application',
                'deployment.buildPack',
                'server' => fn ($q) => $q->withTrashed(),
                'destination',
            ])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhereHas('deployment', fn ($d) => $d->where('name', 'like', '%'.$request->string('search').'%'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('backup_type', $request->string('type')))
            ->when($request->filled('server'), fn ($q) => $q->where('server_id', $request->integer('server')))
            ->when($request->filled('application'), fn ($q) => $q->whereHas('deployment', fn ($d) => $d->where('application_id', $request->integer('application'))))
            ->when($request->sort === 'oldest', fn ($q) => $q->oldest())
            ->when($request->sort === 'size', fn ($q) => $q->orderByDesc('size_bytes'))
            ->when(! in_array($request->sort, ['oldest', 'size'], true), fn ($q) => $q->latest())
            ->paginate(12)
            ->withQueryString();

        $deployments = ApplicationDeployment::where('tenant_id', $tenantId)->with(['server', 'application'])->latest()->get();
        $destinations = BackupDestination::where('tenant_id', $tenantId)->withCount('backups')->get();
        $schedules = BackupSchedule::where('tenant_id', $tenantId)
            ->with(['deployment' => fn ($q) => $q->withTrashed(), 'deployment.application', 'destination'])
            ->latest()
            ->get();
        $servers = Server::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']);
        $applications = \App\Models\Application::query()
            ->whereHas('deployments', fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return view('operations.backups', compact('backups', 'deployments', 'destinations', 'schedules', 'servers', 'applications', 'stats'));
    }

    public function store(Request $request,TenantContext $context):RedirectResponse{$data=$request->validate(['application_deployment_id'=>['required','exists:application_deployments,id'],'backup_destination_id'=>['nullable','exists:backup_destinations,id'],'backup_type'=>['required','in:application,database,volume,full'],'name'=>['nullable','string','max:150']]);$deployment=ApplicationDeployment::where('tenant_id',$context->id())->with('server')->findOrFail($data['application_deployment_id']);$this->authorize('operate',$deployment->server);$destination=!empty($data['backup_destination_id'])?BackupDestination::where('tenant_id',$context->id())->findOrFail($data['backup_destination_id']):null;$backup=Backup::create(['tenant_id'=>$context->id(),'application_deployment_id'=>$deployment->id,'server_id'=>$deployment->server_id,'backup_destination_id'=>$destination?->id,'created_by'=>$request->user()->id,'name'=>($data['name']??null)?:$deployment->name.' '.ucfirst($data['backup_type']).' '.now()->format('Y-m-d H:i'),'backup_type'=>$data['backup_type'],'status'=>'pending']);CreateBackupJob::dispatch($backup->id,$context->id(),$request->user()->id);return back()->with('success','Backup queued.');}
    public function schedule(Request $request,TenantContext $context):RedirectResponse{$data=$request->validate(['application_deployment_id'=>['required','exists:application_deployments,id'],'backup_destination_id'=>['nullable','exists:backup_destinations,id'],'name'=>['required','string','max:120'],'backup_type'=>['required','in:application,database,volume,full'],'frequency'=>['required','in:hourly,daily,weekly'],'keep_last'=>['required','integer','between:1,100'],'delete_after_days'=>['required','integer','between:1,3650']]);$deployment=ApplicationDeployment::where('tenant_id',$context->id())->with('server')->findOrFail($data['application_deployment_id']);$this->authorize('operate',$deployment->server);if(!empty($data['backup_destination_id']))BackupDestination::where('tenant_id',$context->id())->findOrFail($data['backup_destination_id']);BackupSchedule::create($data+['tenant_id'=>$context->id(),'enabled'=>true,'next_run_at'=>now()]);return back()->with('success','Backup schedule created.');}
    public function toggleSchedule(Request $request, BackupSchedule $schedule, TenantContext $context): RedirectResponse
    {
        abort_unless($schedule->tenant_id === $context->id(), 404);
        $this->authorize('operate', $schedule->deployment->server);
        $schedule->update(['enabled' => ! $schedule->enabled]);

        return back()->with('success', $schedule->enabled ? 'Schedule enabled.' : 'Schedule paused.');
    }
    public function destroySchedule(Request $request, BackupSchedule $schedule, TenantContext $context): RedirectResponse
    {
        abort_unless($schedule->tenant_id === $context->id(), 404);
        $this->authorize('operate', $schedule->deployment->server);
        $schedule->delete();

        return back()->with('success', 'Backup schedule removed.');
    }
    public function destination(Request $request,TenantContext $context):RedirectResponse{$server=\App\Models\Server::where('tenant_id',$context->id())->firstOrFail();$this->authorize('operate',$server);$data=$request->validate(['name'=>['required','string','max:100'],'provider'=>['required',Rule::in(['local','s3','r2','b2','spaces','custom_s3'])],'endpoint'=>['nullable','url','max:500',Rule::requiredIf(fn()=>in_array($request->input('provider'),['r2','b2','spaces','custom_s3'],true))],'bucket'=>['nullable','string','max:120',Rule::requiredIf(fn()=>$request->input('provider')!=='local')],'region'=>['nullable','string','max:60'],'access_key'=>['nullable','string','max:500',Rule::requiredIf(fn()=>$request->input('provider')!=='local')],'secret_key'=>['nullable','string','max:1000',Rule::requiredIf(fn()=>$request->input('provider')!=='local')]]);BackupDestination::create($data+['tenant_id'=>$context->id(),'active'=>true,'last_verified_at'=>$data['provider']==='local'?now():null]);return back()->with('success','Encrypted backup destination saved. It will be verified by the first backup.');}
    public function restore(Request $request,Backup $backup,TenantContext $context):RedirectResponse{$this->operate($backup,$context);RestoreBackupJob::dispatch($backup->id,$context->id(),$request->user()->id);return back()->with('success','Restore queued.');}
    public function download(Backup $backup,TenantContext $context,BackupService $service):BinaryFileResponse{$this->guard($backup,$context);$this->authorize('view',$backup->server);try{$path=$service->downloadPath($backup);}catch(\RuntimeException){abort(404);}return response()->download($path,$backup->uuid.'.tar.gz',['Content-Type'=>'application/gzip']);}
    public function destroy(Backup $backup,TenantContext $context,BackupService $service):RedirectResponse{$this->operate($backup,$context);$service->delete($backup);return back()->with('success','Backup deleted.');}
    private function operate(Backup $backup,TenantContext $context):void{$this->guard($backup,$context);$this->authorize('operate',$backup->server);}private function guard(Backup $backup,TenantContext $context):void{abort_unless($backup->tenant_id===$context->id(),404);}
}
