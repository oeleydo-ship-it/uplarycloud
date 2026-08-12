<x-auth-layout title="Install">
    <div class="auth-heading">
        <span class="eyebrow">FIRST-RUN SETUP</span>
        <h1>Install Uplary Cloud</h1>
        <p>Create the platform superadmin and optional basics before anyone else can sign in.</p>
    </div>
    <form method="POST" action="{{ route('install.store') }}" class="form-stack">
        @csrf
        <label class="field">
            <span>Platform name</span>
            <input type="text" name="platform_name" value="{{ old('platform_name', config('app.name', 'Uplary Cloud')) }}" maxlength="80" placeholder="Uplary Cloud">
            @error('platform_name')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label class="field">
            <span>Workspace name</span>
            <input type="text" name="workspace_name" value="{{ old('workspace_name') }}" maxlength="120" placeholder="Admin workspace">
            @error('workspace_name')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <div class="form-grid form-grid--two">
            <label class="field">
                <span>Superadmin name</span>
                <input type="text" name="name" value="{{ old('name') }}" required autofocus>
                @error('name')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label class="field">
                <span>Email address</span>
                <input type="email" name="email" value="{{ old('email') }}" required>
                @error('email')<small class="field-error">{{ $message }}</small>@enderror
            </label>
        </div>
        <div class="form-grid form-grid--two">
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" required autocomplete="new-password">
                @error('password')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label class="field">
                <span>Confirm password</span>
                <input type="password" name="password_confirmation" required autocomplete="new-password">
            </label>
        </div>
        <button class="button button--primary button--full" type="submit">
            Complete installation <i data-lucide="arrow-right"></i>
        </button>
    </form>
    <p class="auth-switch">This wizard runs once. After setup, use the normal sign-in page.</p>
</x-auth-layout>
