<x-admin-layout title="SEO & Analytics">
    <div class="admin-heading"><div><p>SUPERADMIN / WEBSITE</p><h1>SEO & Analytics</h1><span>Manage default metadata, crawler rules, search-console verification, and privacy-conscious analytics IDs.</span></div><a class="button button--secondary" target="_blank" href="{{ route('sitemap') }}"><i data-lucide="map"></i>View sitemap</a></div>
    <form method="post" action="{{ route('admin.seo.update') }}" class="card admin-form">@csrf @method('PUT')
        <div class="admin-card-head"><div><h2>Search defaults</h2><p>Individual public pages can override these values.</p></div></div>
        <div class="admin-form-grid">
            <label><span>Default meta title</span><input name="default_meta_title" value="{{ $settings['default_meta_title'] ?? '' }}"></label>
            <label><span>Canonical base URL</span><input type="url" name="canonical_base_url" value="{{ $settings['canonical_base_url'] ?? config('app.url') }}"></label>
            <label class="wide"><span>Default meta description</span><textarea name="default_meta_description">{{ $settings['default_meta_description'] ?? '' }}</textarea></label>
            <label class="wide"><span>Default social sharing image URL</span><input type="url" name="default_og_image" value="{{ $settings['default_og_image'] ?? '' }}"></label>
            <label><span>Twitter/X handle</span><input name="twitter_handle" placeholder="@uplary" value="{{ $settings['twitter_handle'] ?? '' }}"></label>
        </div>
        <div class="admin-card-head"><div><h2>Search verification</h2><p>Paste only the verification token, not the complete meta tag.</p></div></div>
        <div class="admin-form-grid">
            <label><span>Google Search Console token</span><input name="google_site_verification" value="{{ $settings['google_site_verification'] ?? '' }}"></label>
            <label><span>Bing Webmaster Tools token</span><input name="bing_site_verification" value="{{ $settings['bing_site_verification'] ?? '' }}"></label>
        </div>
        <div class="admin-card-head"><div><h2>Analytics</h2><p>Scripts are emitted only when a valid ID is configured.</p></div></div>
        <div class="admin-form-grid">
            <label><span>Google Analytics measurement ID</span><input name="google_analytics_id" placeholder="G-XXXXXXXXXX" value="{{ $settings['google_analytics_id'] ?? '' }}"></label>
            <label><span>Google Tag Manager container ID</span><input name="google_tag_manager_id" placeholder="GTM-XXXXXXX" value="{{ $settings['google_tag_manager_id'] ?? '' }}"></label>
        </div>
        <div class="admin-switches">
            <label><span><strong>Allow site indexing</strong><small>Controls the default robots policy and robots.txt response.</small></span><input type="checkbox" name="robots_index" value="1" @checked((int)($settings['robots_index'] ?? 1))></label>
            <label><span><strong>Allow crawlers to follow links</strong><small>Pages can override this setting individually.</small></span><input type="checkbox" name="robots_follow" value="1" @checked((int)($settings['robots_follow'] ?? 1))></label>
        </div>
        <div class="admin-card-head"><div><h2>AI blog writer</h2><p>Use an OpenAI-compatible API to generate SEO-ready drafts from keywords and a content brief. The key is encrypted.</p></div></div>
        <div class="admin-form-grid">
            <label><span>API base URL</span><input type="url" name="blog_ai_base_url" value="{{ $ai['blog_ai_base_url'] ?? 'https://api.openai.com/v1' }}"></label>
            <label><span>Model</span><input name="blog_ai_model" value="{{ $ai['blog_ai_model'] ?? 'gpt-5-mini' }}"></label>
            <label class="wide"><span>API key</span><input type="password" name="blog_ai_api_key" autocomplete="new-password" placeholder="Leave blank to keep the current encrypted key"></label>
        </div>
        <button class="button button--primary">Save SEO settings</button>
    </form>
</x-admin-layout>
