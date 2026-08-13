<x-auth-layout title="Sign in">
    <div class="auth-heading"><span class="eyebrow">WELCOME BACK</span><h1>Sign in to your account</h1><p>Manage your servers and deployments from one place.</p></div>
    <form method="POST" action="/login" class="form-stack">@csrf
        <label class="field"><span>Email address</span><div class="input-wrap"><i data-lucide="mail"></i><input type="email" name="email" value="{{ old('email') }}" placeholder="you@company.com" required autofocus></div>@error('email')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field"><span>Password</span><div class="input-wrap"><i data-lucide="lock-keyhole"></i><input type="password" name="password" placeholder="Enter your password" required></div>@error('password')<small class="field-error">{{ $message }}</small>@enderror</label>
        <div class="form-between"><label class="check"><input type="checkbox" name="remember" value="1"><span>Remember me</span></label><a href="{{ route('password.request') }}">Forgot password?</a></div>
        <button class="button button--primary button--full" type="submit">Sign in <i data-lucide="arrow-right"></i></button>
    </form>
    <p class="auth-switch">New to {{ app(\App\Support\Branding::class)->name() }}? <a href="{{ route('register') }}">Create an account</a></p>
</x-auth-layout>
