<x-admin-layout :title="$post->exists ? 'Edit Blog Post' : 'New Blog Post'">
    <div class="admin-heading"><div><p>SUPERADMIN / BLOG</p><h1>{{ $post->exists ? 'Edit blog post' : 'Create blog post' }}</h1><span>Write manually or save the brief first and let AI produce an editable draft.</span></div><a class="button button--secondary" href="{{ route('admin.blog-posts.index') }}">Back to posts</a></div>
    <form method="post" action="{{ $post->exists ? route('admin.blog-posts.update', $post) : route('admin.blog-posts.store') }}" class="card admin-form">@csrf @if($post->exists)@method('PUT')@endif
        <div class="admin-card-head"><div><h2>Article</h2><p>The public post uses semantic HTML from the content field.</p></div></div>
        <div class="admin-form-grid">
            <label class="wide"><span>Title</span><input required name="title" value="{{ old('title', $post->title) }}"></label>
            <label><span>Slug</span><input name="slug" value="{{ old('slug', $post->slug) }}" placeholder="generated-from-title"></label>
            <label><span>Category</span><input name="category" value="{{ old('category', $post->category) }}"></label>
            <label class="wide"><span>Excerpt</span><textarea name="excerpt">{{ old('excerpt', $post->excerpt) }}</textarea></label>
            <label class="wide"><span>Content HTML</span><textarea name="body_html" style="min-height:360px;font-family:Consolas,monospace">{{ old('body_html', $post->body_html) }}</textarea></label>
        </div>
        <div class="admin-card-head"><div><h2>Keywords & SEO</h2><p>Keep the focus keyword aligned with the title, description, opening paragraph, and useful headings.</p></div></div>
        <div class="admin-form-grid">
            <label><span>Focus keyword</span><input name="focus_keyword" value="{{ old('focus_keyword', $post->focus_keyword) }}"></label>
            <label><span>Supporting keywords</span><input name="keywords_text" value="{{ old('keywords_text', implode(', ', $post->keywords ?? [])) }}" placeholder="docker hosting, app deployment"></label>
            <label><span>Meta title</span><input name="meta_title" value="{{ old('meta_title', $post->meta_title) }}"></label>
            <label><span>Read time (minutes)</span><input type="number" min="1" max="60" name="read_minutes" value="{{ old('read_minutes', $post->read_minutes ?: 5) }}"></label>
            <label class="wide"><span>Meta description</span><textarea name="meta_description">{{ old('meta_description', $post->meta_description) }}</textarea></label>
            <label class="wide"><span>Canonical URL</span><input type="url" name="canonical_url" value="{{ old('canonical_url', $post->canonical_url) }}"></label>
            <label class="wide"><span>Social image URL</span><input type="url" name="og_image" value="{{ old('og_image', $post->og_image) }}"></label>
        </div>
        <div class="admin-card-head"><div><h2>Publishing</h2><p>Scheduled posts are published by Laravel's scheduler every minute.</p></div></div>
        <div class="admin-form-grid">
            <label><span>Status</span><select name="status"><option value="draft" @selected(old('status',$post->status)==='draft')>Draft</option><option value="scheduled" @selected(old('status',$post->status)==='scheduled')>Scheduled</option><option value="published" @selected(old('status',$post->status)==='published')>Published</option></select></label>
            <label><span>Schedule date and time</span><input type="datetime-local" name="publish_at" value="{{ old('publish_at', $post->publish_at?->format('Y-m-d\TH:i')) }}"></label>
        </div>
        <div class="admin-switches"><label><span><strong>Index this post</strong><small>Allow search engines to index the article.</small></span><input type="checkbox" name="robots_index" value="1" @checked(old('robots_index',$post->robots_index ?? true))></label><label><span><strong>Follow links</strong><small>Allow crawlers to follow links in the article.</small></span><input type="checkbox" name="robots_follow" value="1" @checked(old('robots_follow',$post->robots_follow ?? true))></label></div>
        <div class="admin-card-head"><div><h2>AI content brief</h2><p>AI output always remains a draft you can review and edit before publishing.</p></div></div>
        <div class="admin-form-grid"><label class="wide"><span>Brief and instructions</span><textarea name="ai_prompt" style="min-height:130px">{{ old('ai_prompt', $post->ai_prompt) }}</textarea></label></div>
        @if($post->ai_error)<div class="alert alert--error">{{ $post->ai_error }}</div>@endif
        <button class="button button--primary">Save post</button>
    </form>
    @if($post->exists)
        <section class="card admin-form" style="margin-top:16px"><div class="admin-card-head"><div><h2>AI automation</h2><p>Status: {{ ucfirst($post->ai_status ?: 'not generated') }}</p></div></div><form method="post" action="{{ route('admin.blog-posts.generate', $post) }}" class="admin-form-grid">@csrf<input type="hidden" name="focus_keyword" value="{{ $post->focus_keyword }}"><input type="hidden" name="keywords_text" value="{{ implode(', ', $post->keywords ?? []) }}"><input type="hidden" name="ai_prompt" value="{{ $post->ai_prompt }}"><button class="button button--secondary" @disabled(!$post->focus_keyword)><i data-lucide="sparkles"></i>Generate SEO draft</button></form></section>
        <form method="post" action="{{ route('admin.blog-posts.destroy', $post) }}" style="margin-top:16px" onsubmit="return confirm('Delete this blog post?')">@csrf @method('DELETE')<button class="button button--danger">Delete post</button></form>
    @endif
</x-admin-layout>
