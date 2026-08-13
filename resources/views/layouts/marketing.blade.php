@php
    $brand = app(\App\Support\Branding::class)->platform();
    $canRegister = (bool) ((int) app(\App\Support\PlatformSettings::class)->get('general', 'registration_enabled', 1));
    $startUrl = auth()->check() ? route('dashboard') : ($canRegister ? route('register') : route('login'));
    $startLabel = auth()->check() ? 'Open dashboard' : 'Get started';
    $nav = [
        ['Features', 'marketing.features'],
        ['Pricing', 'marketing.pricing'],
        ['Use cases', 'marketing.use-cases'],
        ['About', 'marketing.about'],
        ['Blog', 'marketing.blog'],
        ['Contact', 'marketing.contact'],
    ];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? $brand['name'] }} · {{ $brand['name'] }}</title>
    @if(!empty($description))<meta name="description" content="{{ $description }}">@endif
    @if($brand['favicon'])<link rel="icon" href="{{ Storage::url($brand['favicon']) }}">@endif
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet">
    <style>:root{--primary:{{ $brand['primary_color'] }};--secondary:{{ $brand['secondary_color'] }};}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="marketing-body" x-data="{ navOpen: false }">
    <a class="mkt-skip" href="#main">Skip to content</a>
    <header class="mkt-header">
        <div class="mkt-wrap mkt-header-inner">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand-mark">
                    @if($brand['logo'])
                        <img src="{{ Storage::url($brand['logo']) }}" alt="">
                    @else
                        <i data-lucide="layers-3"></i>
                    @endif
                </span>
                <span>{{ $brand['name'] }}</span>
            </a>
            <nav class="mkt-nav" :class="navOpen && 'is-open'" aria-label="Primary">
                @foreach($nav as [$label, $route])
                    <a href="{{ route($route) }}" class="{{ request()->routeIs($route) || request()->routeIs($route.'.*') ? 'is-active' : '' }}">{{ $label }}</a>
                @endforeach
                <div class="mkt-nav-actions">
                    @auth
                        <a class="button button--secondary" href="{{ route('dashboard') }}">Dashboard</a>
                    @else
                        <a class="mkt-login" href="{{ route('login') }}">Log in</a>
                        <a class="button button--primary" href="{{ $startUrl }}">{{ $startLabel }}</a>
                    @endauth
                </div>
            </nav>
            <button class="mkt-menu" type="button" @click="navOpen = !navOpen" :aria-expanded="navOpen" aria-label="Menu">
                <i data-lucide="menu" x-show="!navOpen"></i>
                <i data-lucide="x" x-show="navOpen" x-cloak></i>
            </button>
        </div>
    </header>

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="mkt-footer">
        <div class="mkt-wrap mkt-footer-grid">
            <div>
                <a class="brand" href="{{ route('home') }}">
                    <span class="brand-mark">
                        @if($brand['logo'])
                            <img src="{{ Storage::url($brand['logo']) }}" alt="">
                        @else
                            <i data-lucide="layers-3"></i>
                        @endif
                    </span>
                    <span>{{ $brand['name'] }}</span>
                </a>
                <p>{{ $brand['tagline'] ?: 'Deploy confidently. Operate clearly.' }}</p>
            </div>
            <div>
                <strong>Product</strong>
                <a href="{{ route('marketing.features') }}">Features</a>
                <a href="{{ route('marketing.pricing') }}">Pricing</a>
                <a href="{{ route('marketing.use-cases') }}">Use cases</a>
                <a href="{{ route('marketing.blog') }}">Blog</a>
            </div>
            <div>
                <strong>Company</strong>
                <a href="{{ route('marketing.about') }}">About</a>
                <a href="{{ route('marketing.contact') }}">Contact</a>
                @if($brand['documentation_url'])
                    <a href="{{ $brand['documentation_url'] }}" target="_blank" rel="noreferrer">Docs</a>
                @endif
                @if($brand['website'])
                    <a href="{{ $brand['website'] }}" target="_blank" rel="noreferrer">Website</a>
                @endif
            </div>
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
        <div class="mkt-wrap mkt-footer-copy">
            <span>&copy; {{ date('Y') }} {{ $brand['company_name'] ?: $brand['name'] }}. {{ $brand['copyright'] ?: 'All rights reserved.' }}</span>
        </div>
    </footer>
</body>
</html>
