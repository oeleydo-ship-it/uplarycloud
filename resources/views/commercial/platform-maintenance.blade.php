<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scheduled maintenance</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body">
    <main class="plan-locked" style="margin-top:12vh">
        <span class="plan-locked-icon"><i data-lucide="wrench"></i></span>
        <h1>Scheduled maintenance</h1>
        <p>{{ $message }}</p>
        <div class="plan-locked-actions">
            <a href="{{ url()->current() }}" class="button button--primary">Try again</a>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="button button--secondary">Log out</button></form>
        </div>
    </main>
</body>
</html>
