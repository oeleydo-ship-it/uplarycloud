<x-marketing-layout :title="$post->title" :description="$post->excerpt">
    <div class="mkt-wrap mkt-page">
        <p class="mkt-meta">
            <a href="{{ route('marketing.blog') }}" style="color:var(--primary);font-weight:650">Blog</a>
            <span>{{ $post->category }}</span>
            <time datetime="{{ $post->published_at }}">{{ \Illuminate\Support\Carbon::parse($post->published_at)->format('F j, Y') }}</time>
            <span>{{ $post->read_minutes }} min read</span>
        </p>
        <h1 class="mkt-title" style="max-width:22ch">{{ $post->title }}</h1>
        <div class="mkt-prose" style="margin-top:8px">
            @foreach($post->paragraphs as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </div>

        @if($posts->isNotEmpty())
            <div class="mkt-section-head" style="margin-top:48px">
                <h2>More notes</h2>
            </div>
            <div class="mkt-posts">
                @foreach($posts as $other)
                    <a class="mkt-card mkt-post" href="{{ route('marketing.blog.show', $other->slug) }}">
                        <time datetime="{{ $other->published_at }}">{{ \Illuminate\Support\Carbon::parse($other->published_at)->format('M j, Y') }}</time>
                        <div>
                            <h2>{{ $other->title }}</h2>
                            <p>{{ $other->excerpt }}</p>
                        </div>
                        <span>Read</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-marketing-layout>
