<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="15">
    <title>Server restarting</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, -apple-system, Segoe UI, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0f172a; color: #e2e8f0; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { margin: 0; line-height: 1.6; color: #94a3b8; }
        .pulse { width: 3rem; height: 3rem; margin: 0 auto 1.25rem; border-radius: 999px; border: 3px solid #334155; border-top-color: #38bdf8; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <main>
        <div class="pulse" aria-hidden="true"></div>
        <h1>Server is restarting</h1>
        <p>Please wait while services come back online. This page refreshes automatically.</p>
    </main>
</body>
</html>
