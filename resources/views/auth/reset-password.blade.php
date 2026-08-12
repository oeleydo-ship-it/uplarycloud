<x-auth-layout title="Choose a new password">
    <div class="auth-heading"><span class="eyebrow">SECURE YOUR ACCOUNT</span><h1>Choose a new password</h1><p>Use at least eight characters and avoid reused passwords.</p></div>
    <form method="POST" action="{{ route('password.update') }}" class="form-stack">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="field"><span>Email address</span><input type="email" name="email" value="{{ old('email', $email) }}" required></label>
        <label class="field"><span>New password</span><input type="password" name="password" required>@error('password')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field"><span>Confirm password</span><input type="password" name="password_confirmation" required></label>
        <button class="button button--primary button--full">Reset password <i data-lucide="arrow-right"></i></button>
    </form>
</x-auth-layout>
