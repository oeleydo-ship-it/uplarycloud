<x-marketing-layout :page="$page">
    <div class="mkt-wrap mkt-page">
        @if($page->hero_kicker)<span class="mkt-kicker">{{ $page->hero_kicker }}</span>@endif
        <h1 class="mkt-title">{{ $page->hero_title ?: $page->title }}</h1>
        @if($page->hero_description)<p class="mkt-lead">{{ $page->hero_description }}</p>@endif
        <section class="mkt-managed-content">{!! $page->body_html !!}</section>
    </div>
</x-marketing-layout>
