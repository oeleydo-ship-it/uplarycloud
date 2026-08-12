<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Servers\ServerConnectionTester;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

class ServerConnectionController extends Controller
{
    public function __invoke(Server $server, TenantContext $context, ServerConnectionTester $tester): JsonResponse
    {
        abort_unless($server->tenant_id === $context->id(), 404); $this->authorize('operate', $server);
        return response()->json($tester->test($server));
    }
}
