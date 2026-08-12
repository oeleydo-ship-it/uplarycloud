<x-auth-layout title="Verify email">
    <div class="auth-heading"><span class="eyebrow">ONE LAST STEP</span><h1>Verify your email</h1><p>We sent a verification link to <strong>{{ auth()->user()->email }}</strong>. Open it to activate your workspace.</p></div>
    @if(session('success'))<div class="inline-success">{{ session('success') }}</div>@endif
    <form method="POST" action="{{ route('verification.send') }}" class="form-stack">@csrf<button class="button button--primary button--full">Resend verification email</button></form>
    <form method="POST" action="{{ route('logout') }}" class="auth-switch">@csrf<button class="link-button">Use a different account</button></form>
</x-auth-layout>
