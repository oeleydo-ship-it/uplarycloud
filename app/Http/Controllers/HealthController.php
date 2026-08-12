<?php

namespace App\Http\Controllers;

use App\Services\Platform\ReadinessService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'alive',
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function ready(ReadinessService $readiness): JsonResponse
    {
        $report = $readiness->report();

        return response()->json($report, $report['status'] === 'ready' ? 200 : 503);
    }
}
