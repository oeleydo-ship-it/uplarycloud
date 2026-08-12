<?php

namespace App\Http\Controllers;

use App\Http\Requests\ValidateServerConnectionRequest;
use App\Services\Servers\ControlPlaneKeyService;
use App\Services\Servers\ServerConnectionTester;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

class ServerConnectionValidationController extends Controller
{
    public function __invoke(
        ValidateServerConnectionRequest $request,
        ServerConnectionTester $tester,
        TenantContext $context,
        ControlPlaneKeyService $keys,
    ): JsonResponse {
        $payload = $request->validated();

        if (($payload['authorization_method'] ?? null) === 'platform_key') {
            $payload['authentication_method'] = 'ssh_key';
            $payload['private_key'] = $keys->privateKeyForTenant($context->current());
            $payload['password'] = null;
            $payload['passphrase'] = null;
        }

        unset($payload['authorization_method']);

        return response()->json($tester->validatePayload($payload));
    }
}
