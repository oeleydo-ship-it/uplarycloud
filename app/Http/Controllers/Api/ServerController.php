<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;use App\Http\Resources\ServerResource;use App\Models\Server;use Illuminate\Http\Request;use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
class ServerController extends Controller{public function index(Request $request):AnonymousResourceCollection{abort_unless($request->user()->tokenCan('servers:read'),403);return ServerResource::collection(Server::where('tenant_id',$request->attributes->get('tenant_id'))->latest()->paginate(25));}}
