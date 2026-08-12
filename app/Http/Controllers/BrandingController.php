<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBrandingRequest;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\Branding;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BrandingController extends Controller
{
    public function edit(Branding $branding): View
    {
        return view('settings.branding', ['brandingSettings' => $branding->all()]);
    }

    public function update(UpdateBrandingRequest $request, TenantContext $context): RedirectResponse
    {
        $data = $request->safe()->except(['logo', 'favicon']);
        foreach (['logo', 'favicon'] as $asset) {
            if ($request->hasFile($asset)) {
                $oldPath = Setting::where(['tenant_id' => $context->id(), 'group' => 'branding', 'key' => $asset])->value('value');
                if ($oldPath) Storage::disk('public')->delete($oldPath);
                $data[$asset] = $request->file($asset)->store('branding/'.$context->id(), 'public');
            }
        }
        foreach ($data as $key => $value) {
            Setting::updateOrCreate(
                ['tenant_id' => $context->id(), 'group' => 'branding', 'key' => $key],
                ['value' => $value]
            );
        }
        Cache::forget('branding.'.$context->id());
        ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => 'branding.updated', 'description' => 'Branding settings updated', 'ip_address' => $request->ip()]);
        return back()->with('success', 'Branding settings saved.');
    }
}
