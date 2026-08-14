<?php

namespace App\Http\Controllers;

use App\Jobs\ServerPowerActionJob;
use App\Models\Server;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerPowerController extends Controller
{
    public function store(Request $request, Server $server, TenantContext $context): RedirectResponse
    {
        abort_unless($server->tenant_id === $context->id(), 404);
        $this->authorize('operate', $server);

        $data = $request->validate([
            'action' => ['required', Rule::in(['shutdown', 'reboot', 'restore'])],
        ]);

        ServerPowerActionJob::dispatch($server->fresh(), $data['action'], $request->user()->id);

        $message = match ($data['action']) {
            'shutdown' => 'Shutdown queued for '.$server->name.'.',
            'reboot' => 'Reboot queued for '.$server->name.'.',
            'restore' => 'Clean OS restore and reprovisioning queued for '.$server->name.'.',
        };

        return back()->with('success', $message);
    }
}
