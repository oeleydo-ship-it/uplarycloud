<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGeneralSettingsRequest;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\Branding;
use App\Support\TenantContext;
use App\Support\WorkspaceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class GeneralSettingsController extends Controller
{
    public function edit(WorkspaceSettings $settings, Branding $branding, TenantContext $context): View
    {
        return view('settings.general', [
            'tenant' => $context->current(),
            'generalSettings' => $settings->all(),
            'consoleColors' => [
                'primary_color' => $branding->get('primary_color'),
                'secondary_color' => $branding->get('secondary_color'),
            ],
        ]);
    }

    public function update(UpdateGeneralSettingsRequest $request, TenantContext $context): RedirectResponse
    {
        abort_unless($context->id(), 403);

        $context->current()->update(['name' => $request->validated('name')]);

        foreach ($request->workspaceSettings() as $key => $value) {
            Setting::updateOrCreate(
                ['tenant_id' => $context->id(), 'group' => 'general', 'key' => $key],
                ['value' => $value]
            );
        }

        foreach ($request->consoleColors() as $key => $value) {
            Setting::updateOrCreate(
                ['tenant_id' => $context->id(), 'group' => 'theme', 'key' => $key],
                ['value' => $value]
            );
        }

        Cache::forget('workspace-settings.'.$context->id());
        Cache::forget('console-theme.'.$context->id());
        ActivityLog::create([
            'tenant_id' => $context->id(),
            'user_id' => $request->user()->id,
            'action' => 'settings.general.updated',
            'description' => 'Workspace settings updated',
            'ip_address' => $request->ip(),
            'status' => 'success',
        ]);

        return back()->with('success', 'Workspace settings saved.');
    }
}
