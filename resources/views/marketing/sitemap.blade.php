{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach($pages as $item)
    @php
        $location = match ($item->slug) {
            'home' => route('home'),
            'features' => route('marketing.features'),
            'pricing' => route('marketing.pricing'),
            'use-cases' => route('marketing.use-cases'),
            'about' => route('marketing.about'),
            'contact' => route('marketing.contact'),
            default => route('marketing.page', $item),
        };
    @endphp
    <url>
        <loc>{{ $location }}</loc>
        @if($item->updated_at)<lastmod>{{ $item->updated_at->toAtomString() }}</lastmod>@endif
    </url>
@endforeach
    <url><loc>{{ route('marketing.blog') }}</loc></url>
@foreach($posts as $post)
    <url>
        <loc>{{ route('marketing.blog.show', $post->slug) }}</loc>
        @if($post->updated_at ?? null)<lastmod>{{ $post->updated_at->toAtomString() }}</lastmod>@endif
    </url>
@endforeach
</urlset>
