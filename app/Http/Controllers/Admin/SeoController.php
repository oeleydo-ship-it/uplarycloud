<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SeoController extends Controller
{
    public function edit(PlatformSettings $settings): View
    {
        return view('admin.seo', ['settings' => $settings->group('seo'), 'ai' => $settings->group('blog_ai')]);
    }

    public function update(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'default_meta_title' => ['nullable', 'string', 'max:160'],
            'default_meta_description' => ['nullable', 'string', 'max:320'],
            'canonical_base_url' => ['nullable', 'url', 'max:1000'],
            'default_og_image' => ['nullable', 'url', 'max:1000'],
            'twitter_handle' => ['nullable', 'string', 'max:40'],
            'google_site_verification' => ['nullable', 'string', 'max:255'],
            'bing_site_verification' => ['nullable', 'string', 'max:255'],
            'google_analytics_id' => ['nullable', 'regex:/^(G|UA)-[A-Z0-9-]+$/i', 'max:40'],
            'google_tag_manager_id' => ['nullable', 'regex:/^GTM-[A-Z0-9]+$/i', 'max:40'],
            'robots_index' => ['nullable', 'boolean'],
            'robots_follow' => ['nullable', 'boolean'],
        ]);
        $data['robots_index'] = $request->boolean('robots_index');
        $data['robots_follow'] = $request->boolean('robots_follow');
        $settings->put('seo', $data);

        $ai = $request->validate([
            'blog_ai_base_url' => ['nullable', 'url', 'max:1000'],
            'blog_ai_model' => ['nullable', 'string', 'max:100'],
            'blog_ai_api_key' => ['nullable', 'string', 'max:2000'],
        ]);
        $settings->put('blog_ai', $ai);

        return back()->with('success', 'SEO, verification, and analytics settings updated.');
    }
}
