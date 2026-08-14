@php
    $brand = app(\App\Support\Branding::class)->platform();
    $settings = app(\App\Support\PlatformSettings::class);
    $seo = $settings->group('seo');
    $canRegister = (bool) ((int) $settings->get('general', 'registration_enabled', 1));
    $startUrl = auth()->check() ? route('dashboard') : ($canRegister ? route('register') : route('login'));
    $nav = app(\App\Support\MarketingPages::class)->navigation();
    $pageUrl = fn ($item) => match ($item->slug) {
        'home' => route('home'),
        'features' => route('marketing.features'),
        'pricing' => route('marketing.pricing'),
        'use-cases' => route('marketing.use-cases'),
        'about' => route('marketing.about'),
        'contact' => route('marketing.contact'),
        default => route('marketing.page', $item),
    };
    $resolvedTitle = data_get($page, 'meta_title') ?: ($title ?: ($seo['default_meta_title'] ?? $brand['name']));
    $resolvedDescription = data_get($page, 'meta_description') ?: ($description ?: ($seo['default_meta_description'] ?? $brand['tagline']));
    $canonicalBase = rtrim($seo['canonical_base_url'] ?? config('app.url'), '/');
    $canonical = data_get($page, 'canonical_url') ?: $canonicalBase.(request()->path() === '/' ? '' : '/'.ltrim(request()->path(), '/'));
    $ogImage = data_get($page, 'og_image') ?: ($seo['default_og_image'] ?? null);
    $globalIndex = (bool) ((int) ($seo['robots_index'] ?? 1));
    $globalFollow = (bool) ((int) ($seo['robots_follow'] ?? 1));
    $robots = (($globalIndex && data_get($page, 'robots_index', true)) ? 'index' : 'noindex').','.(($globalFollow && data_get($page, 'robots_follow', true)) ? 'follow' : 'nofollow');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $resolvedTitle }}{{ $resolvedTitle === $brand['name'] ? '' : ' | '.$brand['name'] }}</title>
    <meta name="description" content="{{ $resolvedDescription }}">
    <meta name="robots" content="{{ $robots }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand['name'] }}">
    <meta property="og:title" content="{{ $resolvedTitle }}">
    <meta property="og:description" content="{{ $resolvedDescription }}">
    <meta property="og:url" content="{{ $canonical }}">
    @if($ogImage)<meta property="og:image" content="{{ $ogImage }}">@endif
    <meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $resolvedTitle }}">
    <meta name="twitter:description" content="{{ $resolvedDescription }}">
    @if(!empty($seo['twitter_handle']))<meta name="twitter:site" content="{{ $seo['twitter_handle'] }}">@endif
    @if(!empty($seo['google_site_verification']))<meta name="google-site-verification" content="{{ $seo['google_site_verification'] }}">@endif
    @if(!empty($seo['bing_site_verification']))<meta name="msvalidate.01" content="{{ $seo['bing_site_verification'] }}">@endif
    @if($brand['favicon'])<link rel="icon" href="{{ Storage::url($brand['favicon']) }}">@endif
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet">
    <style>:root{--primary:{{ $brand['primary_color'] }};--secondary:{{ $brand['secondary_color'] }};}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if(!empty($seo['google_tag_manager_id']))
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',@js($seo['google_tag_manager_id']));</script>
    @endif
    @if(!empty($seo['google_analytics_id']))
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($seo['google_analytics_id']) }}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config',@js($seo['google_analytics_id']),{anonymize_ip:true});</script>
    @endif
</head>
<body class="marketing-body" x-data="{ navOpen: false }">
    @if(!empty($seo['google_tag_manager_id']))
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ urlencode($seo['google_tag_manager_id']) }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    @endif
    <a class="mkt-skip" href="#main">Skip to content</a>
    <header class="mkt-header">
        <div class="mkt-wrap mkt-header-inner">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand-mark">@if($brand['logo'])<img src="{{ Storage::url($brand['logo']) }}" alt="">@else<i data-lucide="layers-3"></i>@endif</span>
                <span>{{ $brand['name'] }}</span>
            </a>
            <nav class="mkt-nav" :class="navOpen && 'is-open'" aria-label="Primary">
                @foreach($nav as $navPage)
                    <a href="{{ $pageUrl($navPage) }}" class="{{ data_get($page, 'slug') === $navPage->slug ? 'is-active' : '' }}">{{ $navPage->nav_label ?: $navPage->title }}</a>
                @endforeach
                <a href="{{ route('marketing.blog') }}" class="{{ request()->routeIs('marketing.blog*') ? 'is-active' : '' }}">Blog</a>
                <div class="mkt-nav-actions">
                    @auth
                        <a class="button button--secondary" href="{{ route('dashboard') }}">Dashboard</a>
                    @else
                        <a class="mkt-login" href="{{ route('login') }}">Log in</a>
                        <a class="button button--primary" href="{{ $startUrl }}">{{ auth()->check() ? 'Open dashboard' : 'Get started' }}</a>
                    @endauth
                </div>
            </nav>
            <button class="mkt-menu" type="button" @click="navOpen = !navOpen" :aria-expanded="navOpen" aria-label="Menu"><i data-lucide="menu" x-show="!navOpen"></i><i data-lucide="x" x-show="navOpen" x-cloak></i></button>
        </div>
    </header>
    <main id="main">{{ $slot }}</main>
    <footer class="mkt-footer">
        <div class="mkt-wrap mkt-footer-grid">
            <div><a class="brand" href="{{ route('home') }}"><span class="brand-mark">@if($brand['logo'])<img src="{{ Storage::url($brand['logo']) }}" alt="">@else<i data-lucide="layers-3"></i>@endif</span><span>{{ $brand['name'] }}</span></a><p>{{ $brand['tagline'] ?: 'Deploy confidently. Operate clearly.' }}</p></div>
            <div><strong>Explore</strong>@foreach($nav->take(4) as $navPage)<a href="{{ $pageUrl($navPage) }}">{{ $navPage->nav_label ?: $navPage->title }}</a>@endforeach</div>
            <div><strong>Company</strong><a href="{{ route('marketing.about') }}">About</a><a href="{{ route('marketing.contact') }}">Contact</a><a href="{{ route('marketing.blog') }}">Blog</a>@if($brand['documentation_url'])<a href="{{ $brand['documentation_url'] }}" target="_blank" rel="noreferrer">Docs</a>@endif</div>
            <div>
                <strong>Account</strong>
                @auth
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                @else
                    <a href="{{ route('login') }}">Log in</a>
                    @if($canRegister)
                        <a href="{{ route('register') }}">Create account</a>
                    @endif
                @endauth
            </div>
        </div>
        <div class="mkt-wrap mkt-footer-copy"><span>&copy; {{ date('Y') }} {{ $brand['company_name'] ?: $brand['name'] }}. {{ $brand['copyright'] ?: 'All rights reserved.' }}</span></div>
    </footer>
</body>
</html>
