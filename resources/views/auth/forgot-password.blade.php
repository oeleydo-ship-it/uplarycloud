<x-auth-layout title="Reset password">
    <div class="auth-heading"><span class="eyebrow">ACCOUNT RECOVERY</span><h1>Forgot your password?</h1><p>Enter your email and we’ll send you a secure reset link.</p></div>
    @if(session('success'))<div class="inline-success">{{ session('success') }}</div>@endif
    <form method="POST" action="{{ route('password.email') }}" class="form-stack">@csrf
        <label class="field"><span>Email address</span><div class="input-wrap"><i data-lucide="mail"></i><input type="email" name="email" value="{{ old('email') }}" required autofocus></div>@error('email')<small class="field-error">{{ $message }}</small>@enderror</label>
        <button class="button button--primary button--full">Send reset link <i data-lucide="arrow-right"></i></button>
    </form>
    <p class="auth-switch"><a href="{{ route('login') }}">Back to sign in</a></p>
</x-auth-layout>
