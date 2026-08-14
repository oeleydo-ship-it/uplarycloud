<x-admin-layout title="Blog Posts">
    <div class="admin-heading"><div><p>SUPERADMIN / WEBSITE</p><h1>Blog Posts</h1><span>Create SEO-ready articles, queue AI drafts, and schedule automatic publishing.</span></div><a class="button button--primary" href="{{ route('admin.blog-posts.create') }}"><i data-lucide="plus"></i>New post</a></div>
    <section class="card admin-table-card">
        <table class="admin-table"><thead><tr><th>Post</th><th>Keywords</th><th>Status</th><th>Publish date</th><th>AI</th><th></th></tr></thead><tbody>
        @forelse($posts as $post)
            <tr><td><strong>{{ $post->title }}</strong><small>/blog/{{ $post->slug }}</small></td><td>{{ $post->focus_keyword ?: '—' }}</td><td><span class="status status--{{ $post->status === 'published' ? 'success' : ($post->status === 'scheduled' ? 'warning' : 'neutral') }}"><i></i>{{ ucfirst($post->status) }}</span></td><td>{{ ($post->publish_at ?: $post->published_at)?->format('M j, Y H:i') ?: '—' }}</td><td>{{ ucfirst($post->ai_status ?: 'manual') }}</td><td><a class="button button--secondary" href="{{ route('admin.blog-posts.edit', $post) }}">Edit</a></td></tr>
        @empty<tr><td colspan="6"><div class="admin-empty"><h3>No managed posts yet</h3><p>Create a manual post or generate your first AI-assisted draft.</p></div></td></tr>@endforelse
        </tbody></table>
    </section>
    {{ $posts->links() }}
</x-admin-layout>
