<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformServiceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlatformServiceController extends Controller
{
    public function index(PlatformServiceManager $manager): View
    {
        return view('admin.services', ['services' => $manager->all()]);
    }

    public function control(string $service, string $action, PlatformServiceManager $manager): RedirectResponse
    {
        abort_unless(array_key_exists($service, config('platform_services.services', [])), 404);
        abort_unless(in_array($action, ['start', 'stop', 'restart'], true), 404);

        $result = $manager->control($service, $action);

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->withErrors(['service' => $result['message']]);
    }
}
