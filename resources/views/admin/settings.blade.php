<x-admin-layout title="General Settings">
    <div class="admin-heading">
        <div>
            <p>SUPERADMIN / SETTINGS</p>
            <h1>General Settings</h1>
            <span>Platform-wide access and operating defaults. Logo, product name, and default colors live under Branding. Tenants can override console colors only.</span>
        </div>
    </div>
    <form class="card admin-form" method="post" action="{{ route('admin.settings.update') }}">
        @csrf @method('PUT')
        <div class="admin-card-head">
            <div>
                <h2>Platform Configuration</h2>
                <p>These settings apply to every tenant. Workspace owners cannot change them from /settings.</p>
            </div>
        </div>
        <div class="admin-form-grid">
            <label>
                <span>Platform name</span>
                <input name="platform_name" value="{{ old('platform_name', $settings['platform_name'] ?? 'Uplary Cloud') }}" required>
            </label>
            <label>
                <span>Platform URL</span>
                <input type="url" name="platform_url" value="{{ old('platform_url', $settings['platform_url'] ?? config('app.url')) }}" required>
            </label>
            <label>
                <span>Support email</span>
                <input type="email" name="support_email" value="{{ old('support_email', $settings['support_email'] ?? 'support@uplary.com') }}" required>
            </label>
            <label>
                <span>Default timezone</span>
                <select name="default_timezone">
                    @foreach (['UTC', 'Asia/Dubai', 'Europe/London', 'America/New_York', 'Asia/Singapore'] as $zone)
                        <option value="{{ $zone }}" @selected(old('default_timezone', $settings['default_timezone'] ?? 'UTC') === $zone)>{{ $zone }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Default language</span>
                <select name="default_language">
                    @foreach (['en' => 'English', 'ar' => 'Arabic', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('default_language', $settings['default_language'] ?? 'en') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Default currency</span>
                <select name="default_currency">
                    @foreach (['USD', 'EUR', 'GBP', 'AED'] as $currency)
                        <option value="{{ $currency }}" @selected(old('default_currency', $settings['default_currency'] ?? 'USD') === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Date format</span>
                <select name="date_format">
                    @foreach (['M j, Y' => 'May 12, 2024', 'd/m/Y' => '12/05/2024', 'm/d/Y' => '05/12/2024', 'Y-m-d' => '2024-05-12'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('date_format', $settings['date_format'] ?? 'M j, Y') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Time format</span>
                <select name="time_format">
                    <option value="g:i A" @selected(old('time_format', $settings['time_format'] ?? 'g:i A') === 'g:i A')>12 Hour (02:30 PM)</option>
                    <option value="H:i" @selected(old('time_format', $settings['time_format'] ?? 'g:i A') === 'H:i')>24 Hour (14:30)</option>
                </select>
            </label>
            <label style="grid-column:1 / -1">
                <span>Maintenance message</span>
                <textarea name="maintenance_message" rows="3" maxlength="500" placeholder="Tell customers why the console is unavailable and when to check back.">{{ old('maintenance_message', $settings['maintenance_message'] ?? 'We are completing scheduled maintenance. Please check back shortly.') }}</textarea>
            </label>
        </div>
        <div class="admin-switches">
            @foreach ([
                ['registration_enabled', 'Public registration', 'Allow new customers to create accounts'],
                ['email_verification', 'Require email verification', 'When on, users must verify before console access. Turn off for local/dev or when SMTP is unavailable.'],
                ['maintenance_mode', 'Maintenance mode', 'Restrict the customer console to administrators'],
                ['read_only_mode', 'Read-only mode', 'Keep the console visible while preventing customer changes during sensitive operations'],
                ['managed_servers_enabled', 'Managed servers', 'Allow tenants to order managed servers from platform cloud APIs'],
            ] as [$key, $label, $copy])
                <label>
                    <span><strong>{{ $label }}</strong><small>{{ $copy }}</small></span>
                    <input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $settings[$key] ?? ($key !== 'maintenance_mode' && $key !== 'managed_servers_enabled' ? 1 : 0)) == 1)>
                </label>
            @endforeach
        </div>
        <div class="admin-card-head">
            <div>
                <h2>Platform Feature Controls</h2>
                <p>Enable or pause modules for every customer workspace. Superadmins retain access for diagnostics and configuration.</p>
            </div>
        </div>
        <div class="admin-switches">
            @foreach ([
                ['marketplace_enabled', 'Application marketplace', 'Allow tenants to browse and install catalog applications'],
                ['git_deployments_enabled', 'Git deployments', 'Allow source-based application builds and deployments'],
                ['custom_docker_enabled', 'Custom Docker workloads', 'Allow custom images and Docker Compose workloads'],
                ['monitoring_enabled', 'Infrastructure monitoring', 'Expose metrics collection and monitoring dashboards'],
                ['alerts_enabled', 'Alerts and incidents', 'Allow alert rules, acknowledgements, and incident resolution'],
                ['backups_enabled', 'Backups and restores', 'Allow backup destinations, schedules, downloads, and restores'],
                ['api_tokens_enabled', 'API token management', 'Allow tenants to create and manage API credentials'],
                ['support_enabled', 'Support center', 'Allow tenants to open and reply to support tickets'],
            ] as [$key, $label, $copy])
                <label>
                    <span><strong>{{ $label }}</strong><small>{{ $copy }}</small></span>
                    <input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $settings[$key] ?? 1) == 1)>
                </label>
            @endforeach
        </div>
        <button class="button button--primary">Save General Settings</button>
    </form>
</x-admin-layout>
