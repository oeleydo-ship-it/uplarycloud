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
    public function edit(WorkspaceSettings $settings, Branding $branding): View
    {
        return view('settings.general', ['generalSettings' => $settings->all(), 'brandingSettings' => $branding->all()]);
    }

    public function update(UpdateGeneralSettingsRequest $request, TenantContext $context): RedirectResponse
    {
        $data = $request->validated();
        $context->current()->update(['name' => $data['name']]);
        unset($data['name']);
        $data['maintenance_mode'] = $request->boolean('maintenance_mode') ? '1' : '0';
        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['tenant_id' => $context->id(), 'group' => 'general', 'key' => $key], ['value' => $value]);
        }
        Cache::forget('workspace-settings.'.$context->id());
        ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => 'settings.general.updated', 'description' => 'General workspace settings updated', 'ip_address' => $request->ip(), 'status' => 'success']);

        return back()->with('success', 'Platform settings saved.');
    }
}
