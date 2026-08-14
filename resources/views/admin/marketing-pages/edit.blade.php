<x-admin-layout :title="$page->exists ? 'Edit '.$page->title : 'New Public Page'">
    <div class="admin-heading"><div><p>SUPERADMIN / WEBSITE</p><h1>{{ $page->exists ? 'Edit '.$page->title : 'Create public page' }}</h1><span>Use semantic HTML in the body editor. Headings, paragraphs, lists, links, cards, and callouts inherit the public design system.</span></div>@if($page->exists && $page->published)<a class="button button--secondary" target="_blank" href="{{ $page->slug === 'home' ? route('home') : ($page->isCore() ? url('/'.$page->slug) : route('marketing.page', $page)) }}"><i data-lucide="external-link"></i>Preview</a>@endif</div>
    <form class="card admin-form admin-page-editor" method="post" action="{{ $page->exists ? route('admin.marketing-pages.update', $page) : route('admin.marketing-pages.store') }}">@csrf @if($page->exists)@method('PUT')@endif
        <div class="admin-card-head"><div><h2>Page content</h2><p>Core page slugs are protected because they map to product routes.</p></div></div>
        <div class="admin-form-grid">
            <label><span>Internal title</span><input name="title" value="{{ old('title', $page->title) }}" required></label>
            <label><span>URL slug</span><input name="slug" value="{{ old('slug', $page->slug) }}" required @readonly($page->exists && $page->isCore())></label>
            <label><span>Navigation label</span><input name="nav_label" value="{{ old('nav_label', $page->nav_label) }}"></label>
            <label><span>Sort position</span><input type="number" name="position" value="{{ old('position', $page->position ?? 100) }}" min="0"></label>
            <label><span>Hero eyebrow</span><input name="hero_kicker" value="{{ old('hero_kicker', $page->hero_kicker) }}"></label>
            <label class="wide"><span>Hero headline</span><input name="hero_title" value="{{ old('hero_title', $page->hero_title) }}"></label>
            <label class="wide"><span>Hero description</span><textarea name="hero_description">{{ old('hero_description', $page->hero_description) }}</textarea></label>
            <label class="wide"><span>Custom body HTML</span><textarea class="admin-code-editor" name="body_html" placeholder="Leave empty to use the built-in content for core pages.">{{ old('body_html', $page->body_html) }}</textarea><small>Custom HTML replaces the built-in main content for core pages and provides the complete body for custom pages.</small></label>
        </div>
        <div class="admin-card-head"><div><h2>Page SEO</h2><p>Leave fields empty to inherit the global SEO defaults.</p></div></div>
        <div class="admin-form-grid">
            <label><span>Meta title</span><input name="meta_title" value="{{ old('meta_title', $page->meta_title) }}"></label>
            <label><span>Canonical URL</span><input type="url" name="canonical_url" value="{{ old('canonical_url', $page->canonical_url) }}"></label>
            <label class="wide"><span>Meta description</span><textarea name="meta_description">{{ old('meta_description', $page->meta_description) }}</textarea></label>
            <label class="wide"><span>Open Graph image URL</span><input type="url" name="og_image" value="{{ old('og_image', $page->og_image) }}"></label>
        </div>
        <div class="admin-switches">
            @foreach([['published','Published','Make this page publicly accessible.'],['show_in_nav','Show in navigation','Include this page in the public header and footer.'],['robots_index','Allow indexing','Permit search engines to index this page.'],['robots_follow','Follow links','Permit search engines to follow links on this page.']] as [$key,$label,$copy])
                <label><span><strong>{{ $label }}</strong><small>{{ $copy }}</small></span><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $page->{$key} ?? true))></label>
            @endforeach
        </div>
        <button class="button button--primary">Save page</button>
    </form>
    @if($page->exists && ! $page->isCore())<form method="post" action="{{ route('admin.marketing-pages.destroy', $page) }}" style="margin-top:14px">@csrf @method('DELETE')<button class="button button--secondary" onclick="return confirm('Delete this public page?')">Delete page</button></form>@endif
</x-admin-layout>
