<x-dashboard-layout title="Settings">
    <div class="page-heading workspace-settings-heading">
        <div>
            <p class="breadcrumb">Workspace / Settings</p>
            <h1>Settings</h1>
            <p>Workspace name, locale, and console colors for this tenant.</p>
        </div>
        <button form="workspace-settings-form" class="button button--primary" type="submit">
            <i data-lucide="save"></i> Save changes
        </button>
    </div>

    @if(auth()->user()->is_super_admin)
        <a class="workspace-settings-banner" href="{{ route('admin.settings') }}">
            <span class="workspace-settings-banner__icon"><i data-lucide="shield-check"></i></span>
            <span>
                <strong>Platform settings live in the Platform Console</strong>
                <small>Product name and logo live under Branding. Maintenance, SMTP, gateways, tenants, and plans are not managed here.</small>
            </span>
            <em>Open console <i data-lucide="arrow-right"></i></em>
        </a>
    @endif

    <div class="workspace-settings-grid">
        <section class="settings-main">
            <form id="workspace-settings-form" method="post" action="{{ route('settings.update') }}">
                @csrf @method('PUT')
                <article class="card settings-section">
                    <div class="section-heading">
                        <span class="section-icon"><i data-lucide="building-2"></i></span>
                        <div>
                            <h2>Workspace</h2>
                            <p>This name is shown to your team across the console.</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <label class="field">
                            <span>Workspace name</span>
                            <input name="name" value="{{ old('name', $tenant->name) }}" required maxlength="80">
                            @error('name')<small class="field-error">{{ $message }}</small>@enderror
                        </label>
                    </div>
                </article>

                <article class="card settings-section">
                    <div class="section-heading">
                        <span class="section-icon"><i data-lucide="globe-2"></i></span>
                        <div>
                            <h2>Locale &amp; formats</h2>
                            <p>Timezone, language, and date display for this workspace.</p>
                        </div>
                    </div>
                    <div class="form-grid form-grid--two">
                        <label class="field">
                            <span>Timezone</span>
                            <select name="timezone">
                                @foreach (['UTC' => '(UTC+00:00) UTC', 'Asia/Dubai' => '(UTC+04:00) Asia/Dubai', 'Europe/London' => 'Europe/London', 'America/New_York' => 'America/New York', 'Asia/Singapore' => 'Asia/Singapore'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('timezone', $generalSettings['timezone']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field">
                            <span>Language</span>
                            <select name="language">
                                @foreach (['en' => 'English', 'ar' => 'Arabic', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('language', $generalSettings['language']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field">
                            <span>Date format</span>
                            <select name="date_format">
                                @foreach (['M j, Y' => 'May 12, 2024 (MMM DD, YYYY)', 'd/m/Y' => '12/05/2024 (DD/MM/YYYY)', 'm/d/Y' => '05/12/2024 (MM/DD/YYYY)', 'Y-m-d' => '2024-05-12 (YYYY-MM-DD)'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('date_format', $generalSettings['date_format']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field">
                            <span>Time format</span>
                            <select name="time_format">
                                <option value="g:i A" @selected(old('time_format', $generalSettings['time_format']) === 'g:i A')>12 Hour (02:30 PM)</option>
                                <option value="H:i" @selected(old('time_format', $generalSettings['time_format']) === 'H:i')>24 Hour (14:30)</option>
                            </select>
                        </label>
                    </div>
                </article>

                <article class="card settings-section">
                    <div class="section-heading">
                        <span class="section-icon"><i data-lucide="palette"></i></span>
                        <div>
                            <h2>Console colors</h2>
                            <p>These colors apply only to this workspace console. Product name and logo are set by the platform.</p>
                        </div>
                    </div>
                    <div class="form-grid form-grid--two">
                        <label class="field">
                            <span>Primary color</span>
                            <div class="color-field">
                                <input type="color" value="{{ old('primary_color', $consoleColors['primary_color']) }}" oninput="this.nextElementSibling.value=this.value">
                                <input name="primary_color" value="{{ old('primary_color', $consoleColors['primary_color']) }}" required>
                            </div>
                        </label>
                        <label class="field">
                            <span>Secondary color</span>
                            <div class="color-field">
                                <input type="color" value="{{ old('secondary_color', $consoleColors['secondary_color']) }}" oninput="this.nextElementSibling.value=this.value">
                                <input name="secondary_color" value="{{ old('secondary_color', $consoleColors['secondary_color']) }}" required>
                            </div>
                        </label>
                    </div>
                    <div class="brand-preview" style="--preview-primary:{{ old('primary_color', $consoleColors['primary_color']) }};--preview-secondary:{{ old('secondary_color', $consoleColors['secondary_color']) }}">
                        <div>
                            <span class="brand-mark"><i data-lucide="layers-3"></i></span>
                            <strong>Console preview</strong>
                        </div>
                        <button type="button">Primary action</button>
                        <a href="#">Example link</a>
                    </div>
                </article>
            </form>
        </section>

        <aside class="workspace-settings-aside">
            <article class="card workspace-link-card">
                <div class="reference-card-title">
                    <h2>Workspace</h2>
                    <p>Team and billing stay in this tenant console.</p>
                </div>
                <a href="{{ route('team.index') }}">
                    <i data-lucide="user-round-plus"></i>
                    <span><strong>Invite teammates</strong><small>Roles and access for this workspace</small></span>
                    <i data-lucide="chevron-right"></i>
                </a>
                <a href="{{ route('billing.index') }}">
                    <i data-lucide="credit-card"></i>
                    <span><strong>Plan &amp; billing</strong><small>Your subscription, not platform gateways</small></span>
                    <i data-lucide="chevron-right"></i>
                </a>
                <a href="{{ route('support.index') }}">
                    <i data-lucide="life-buoy"></i>
                    <span><strong>Contact support</strong><small>Open a request for this workspace</small></span>
                    <i data-lucide="chevron-right"></i>
                </a>
            </article>
            <article class="card help-card">
                <i data-lucide="info"></i>
                <div>
                    <h3>What you can change here</h3>
                    <p>Workspace name, locale, and console colors. Product name and logo stay in the Platform Console.</p>
                </div>
            </article>
        </aside>
    </div>
</x-dashboard-layout>
