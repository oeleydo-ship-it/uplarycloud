<?php

namespace App\Http\Controllers;

use App\Events\DeploymentProgressed;use App\Jobs\ProcessWebApplicationDeploymentJob;use App\Models\ApplicationDeployment;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;

class GitWebhookController extends Controller
{
    public function __invoke(Request $request,ApplicationDeployment $deployment): JsonResponse
    {
        abort_unless($deployment->deployment_type==='git'&&$deployment->auto_deploy,404);$secret=$deployment->webhook_secret;$github=(string)$request->header('X-Hub-Signature-256');$gitlab=(string)$request->header('X-Gitlab-Token');$valid=($github&&hash_equals('sha256='.hash_hmac('sha256',$request->getContent(),$secret),$github))||($gitlab&&hash_equals($secret,$gitlab));abort_unless($valid,403);$commit=$request->input('after')??$request->input('checkout_sha')??$request->input('push.changes.0.new.target.hash');$deployment->steps()->update(['status'=>'pending','started_at'=>null,'completed_at'=>null,'error'=>null]);$deployment->update(['status'=>'queued','progress'=>0,'current_stage'=>'queued','commit_hash'=>$commit,'last_webhook_at'=>now(),'last_error'=>null,'build_status'=>'queued']);ProcessWebApplicationDeploymentJob::dispatch($deployment->id,$deployment->tenant_id,null);event(new DeploymentProgressed($deployment->tenant_id,$deployment->uuid,'queued',0,'queued'));return response()->json(['accepted'=>true,'deployment'=>$deployment->uuid],202);
    }
}
