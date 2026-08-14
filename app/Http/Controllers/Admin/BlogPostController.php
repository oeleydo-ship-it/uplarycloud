<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateBlogPostJob;
use App\Models\BlogPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BlogPostController extends Controller
{
    public function index(): View
    {
        return view('admin.blog-posts.index', ['posts' => BlogPost::latest()->paginate(20)]);
    }

    public function create(): View
    {
        return view('admin.blog-posts.edit', ['post' => new BlogPost(['status' => 'draft', 'robots_index' => true, 'robots_follow' => true, 'read_minutes' => 5])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $post = BlogPost::create($this->data($request) + ['author_id' => $request->user()->id]);

        return redirect()->route('admin.blog-posts.edit', $post)->with('success', 'Blog post created.');
    }

    public function edit(BlogPost $post): View
    {
        return view('admin.blog-posts.edit', compact('post'));
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $post->update($this->data($request, $post));

        return back()->with('success', 'Blog post updated.');
    }

    public function generate(Request $request, BlogPost $post): RedirectResponse
    {
        $request->validate(['ai_prompt' => ['nullable', 'string', 'max:5000'], 'focus_keyword' => ['required', 'string', 'max:160'], 'keywords_text' => ['nullable', 'string', 'max:2000']]);
        $post->update([
            'ai_prompt' => $request->input('ai_prompt'),
            'focus_keyword' => $request->string('focus_keyword')->toString(),
            'keywords' => $this->keywords($request->input('keywords_text')),
            'ai_status' => 'queued',
            'ai_error' => null,
        ]);
        GenerateBlogPostJob::dispatch($post->id);

        return back()->with('success', 'AI article generation queued. Refresh after the worker completes it.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $post->delete();

        return redirect()->route('admin.blog-posts.index')->with('success', 'Blog post deleted.');
    }

    private function data(Request $request, ?BlogPost $post = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'alpha_dash', 'max:160', Rule::unique('blog_posts')->ignore($post)],
            'category' => ['nullable', 'string', 'max:100'],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'body_html' => ['nullable', 'string', 'max:500000'],
            'focus_keyword' => ['nullable', 'string', 'max:160'],
            'keywords_text' => ['nullable', 'string', 'max:2000'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'canonical_url' => ['nullable', 'url', 'max:1000'],
            'og_image' => ['nullable', 'url', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'scheduled', 'published'])],
            'publish_at' => ['nullable', 'date'],
            'read_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'ai_prompt' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['slug'] = $data['slug'] ?: Str::slug($data['title']);
        $data['keywords'] = $this->keywords($request->input('keywords_text'));
        $data['robots_index'] = $request->boolean('robots_index');
        $data['robots_follow'] = $request->boolean('robots_follow');
        if ($data['status'] === 'published') {
            $data['published_at'] = $post?->published_at ?? now();
        }
        if ($data['status'] === 'scheduled' && blank($data['publish_at'])) {
            abort(422, 'A scheduled post requires a publishing date.');
        }

        unset($data['keywords_text']);

        return $data;
    }

    private function keywords(?string $value): array
    {
        return collect(preg_split('/[,\r\n]+/', (string) $value))->map(fn ($keyword) => trim($keyword))->filter()->unique()->take(30)->values()->all();
    }
}
