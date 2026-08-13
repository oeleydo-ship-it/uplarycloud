<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBrandingRequest;
use App\Support\Branding;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BrandingController extends Controller
{
    public function edit(Branding $branding): View
    {
        return view('admin.branding', ['brandingSettings' => $branding->platform()]);
    }

    public function update(UpdateBrandingRequest $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->safe()->except(['logo', 'favicon']);
        $current = $settings->group('branding');

        foreach (['logo', 'favicon'] as $asset) {
            if ($request->hasFile($asset)) {
                $oldPath = $current[$asset] ?? null;
                if ($oldPath) {
                    Storage::disk('public')->delete($oldPath);
                }
                $data[$asset] = $request->file($asset)->store('branding/platform', 'public');
            }
        }

        $settings->put('branding', $data);
        Cache::forget(Branding::CACHE_KEY);

        return back()->with('success', 'Platform branding saved.');
    }
}
