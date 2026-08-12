<x-auth-layout title="Create account">
    <div class="auth-heading"><span class="eyebrow">START DEPLOYING</span><h1>Create your workspace</h1><p>Everything you need to operate Docker infrastructure.</p></div>
    <form method="POST" action="/register" class="form-stack">@csrf
        <div class="form-grid form-grid--two">
            <label class="field"><span>Your name</span><input type="text" name="name" value="{{ old('name') }}" required>@error('name')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label class="field"><span>Workspace</span><input type="text" name="workspace_name" value="{{ old('workspace_name') }}" required>@error('workspace_name')<small class="field-error">{{ $message }}</small>@enderror</label>
        </div>
        <label class="field"><span>Email address</span><input type="email" name="email" value="{{ old('email') }}" required>@error('email')<small class="field-error">{{ $message }}</small>@enderror</label>
        <div class="form-grid form-grid--two">
            <label class="field"><span>Password</span><input type="password" name="password" required>@error('password')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label class="field"><span>Confirm password</span><input type="password" name="password_confirmation" required></label>
        </div>
        <label class="check"><input type="checkbox" name="terms" value="1" required><span>I agree to the Terms and Privacy Policy</span></label>
        <button class="button button--primary button--full" type="submit">Create workspace <i data-lucide="arrow-right"></i></button>
    </form>
    <p class="auth-switch">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
</x-auth-layout>
