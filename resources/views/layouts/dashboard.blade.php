@php($brand = app(\App\Support\Branding::class)->all())
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} · {{ $brand['name'] }}</title>
    @if($brand['favicon'])<link rel="icon" href="{{ Storage::url($brand['favicon']) }}">@endif
    <style>:root{--primary:{{ $brand['primary_color'] }};--secondary:{{ $brand['secondary_color'] }};}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body" x-data="{ sidebarOpen: false, profileOpen: false }">
    <div class="app-shell">
        @include('partials.sidebar', ['brand' => $brand])
        <div class="app-main">
            @include('partials.header', ['brand' => $brand])
            <main class="page-content">
                @if(session('success'))
                    <div class="toast" x-data="{show:true}" x-show="show" x-transition>
                        <i data-lucide="circle-check"></i><span>{{ session('success') }}</span>
                        <button @click="show=false"><i data-lucide="x"></i></button>
                    </div>
                @endif
                @if(session('error'))
                    <div class="toast toast--error" x-data="{show:true}" x-show="show" x-transition>
                        <i data-lucide="triangle-alert"></i><span>{{ session('error') }}</span>
                        <button @click="show=false"><i data-lucide="x"></i></button>
                    </div>
                @endif
                @if($errors->any() && str_contains(strtolower($errors->first()), 'plan'))
                    <div class="toast toast--error" x-data="{show:true}" x-show="show" x-transition>
                        <i data-lucide="triangle-alert"></i>
                        <span>{{ $errors->first() }} <a href="{{ route('billing.index') }}">View plans</a></span>
                        <button @click="show=false"><i data-lucide="x"></i></button>
                    </div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
