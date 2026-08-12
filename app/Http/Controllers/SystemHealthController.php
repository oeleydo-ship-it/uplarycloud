<?php

namespace App\Http\Controllers;

use App\Services\Platform\ReadinessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    public function __invoke(ReadinessService $readiness): View
    {
        return view('operations.system-health', [
            'report' => $readiness->report(),
            'runtime' => [
                'Application' => config('app.name'),
                'Environment' => app()->environment(),
                'Laravel' => app()->version(),
                'PHP' => PHP_VERSION,
                'Database' => config('database.default'),
                'Cache' => config('cache.default'),
                'Queue' => config('queue.default'),
            ],
            'failedJobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
        ]);
    }
}
