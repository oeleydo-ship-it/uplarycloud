<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-body">
    <div class="auth-shell">
        <section class="auth-panel">
            <a href="/" class="brand brand--large">
                <span class="brand-mark"><i data-lucide="layers-3"></i></span>
                <span>{{ config('app.name') }}</span>
            </a>
            <div class="auth-card">
                {{ $slot }}
            </div>
            <p class="auth-footer">Secure deployment control plane · All systems encrypted</p>
        </section>
        <aside class="auth-visual">
            <div class="auth-orb auth-orb--one"></div>
            <div class="auth-orb auth-orb--two"></div>
            <div class="visual-copy">
                <span class="eyebrow">DOCKER OPERATIONS, SIMPLIFIED</span>
                <h2>From server to production in a few confident steps.</h2>
                <p>Connect infrastructure, ship containers, monitor health, and keep every deployment under control.</p>
                <div class="visual-steps">
                    <span><i data-lucide="server"></i> Connect server</span>
                    <span><i data-lucide="box"></i> Deploy app</span>
                    <span><i data-lucide="globe-2"></i> Go live</span>
                </div>
            </div>
        </aside>
    </div>
</body>
</html>
