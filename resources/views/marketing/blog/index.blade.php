<x-marketing-layout title="Blog" description="Notes on deploying and operating applications with Uplary Cloud.">
    <div class="mkt-wrap mkt-page">
        <span class="mkt-kicker">Blog</span>
        <h1 class="mkt-title">Operating notes.</h1>
        <p class="mkt-lead">Short pieces on connecting hosts, shipping apps, and keeping client stacks from turning into a spreadsheet.</p>

        <div class="mkt-posts" style="margin-top:32px">
            @foreach($posts as $post)
                <a class="mkt-card mkt-post" href="{{ route('marketing.blog.show', $post->slug) }}">
                    <time datetime="{{ $post->published_at }}">{{ \Illuminate\Support\Carbon::parse($post->published_at)->format('M j, Y') }}</time>
                    <div>
                        <h2>{{ $post->title }}</h2>
                        <p>{{ $post->excerpt }}</p>
                    </div>
                    <span>Read</span>
                </a>
            @endforeach
        </div>
    </div>
</x-marketing-layout>
