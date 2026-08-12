<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;use App\Http\Resources\DeploymentResource;use App\Models\ApplicationDeployment;use Illuminate\Http\Request;use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
class DeploymentController extends Controller{public function index(Request $request):AnonymousResourceCollection{abort_unless($request->user()->tokenCan('deployments:read')||$request->user()->tokenCan('applications:read'),403);return DeploymentResource::collection(ApplicationDeployment::where('tenant_id',$request->attributes->get('tenant_id'))->with('server')->latest()->paginate(25));}}
