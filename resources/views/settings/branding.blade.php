<x-dashboard-layout title="Branding settings">
    <div class="page-heading"><div><p class="breadcrumb">Settings / Branding</p><h1>Settings</h1><p>Manage your workspace identity and product experience.</p></div><button form="branding-form" class="button button--primary" type="submit"><i data-lucide="save"></i> Save changes</button></div>
    <div class="settings-tabs"><a href="{{ route('settings') }}">General</a><a class="is-active" href="{{ route('settings.branding') }}">Branding</a><a href="{{ route('team.index') }}">Workspace</a><a href="{{ route('api-tokens.index') }}">Security</a><a href="{{ route('billing.index') }}">Billing</a><a href="{{ route('system-health') }}">Advanced</a></div>
    <form id="branding-form" method="POST" enctype="multipart/form-data" action="{{ route('settings.branding.update') }}" class="settings-layout">@csrf @method('PUT')
        <section class="settings-main">
            <article class="card settings-section"><div class="section-heading"><span class="section-icon"><i data-lucide="palette"></i></span><div><h2>Product identity</h2><p>Names and copy shown throughout your workspace.</p></div></div>
                <div class="form-grid form-grid--two">
                    <label class="field"><span>Application name</span><input name="name" value="{{ old('name', $brandingSettings['name']) }}" required>@error('name')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label class="field"><span>Short name</span><input name="short_name" value="{{ old('short_name', $brandingSettings['short_name']) }}" maxlength="8" required></label>
                    <label class="field field--wide"><span>Tagline</span><input name="tagline" value="{{ old('tagline', $brandingSettings['tagline']) }}"></label>
                    <label class="field"><span>Company name</span><input name="company_name" value="{{ old('company_name', $brandingSettings['company_name']) }}"></label>
                    <label class="field"><span>Website</span><input type="url" name="website" value="{{ old('website', $brandingSettings['website']) }}" placeholder="https://example.com"></label>
                </div>
            </article>
            <article class="card settings-section"><div class="section-heading"><span class="section-icon"><i data-lucide="swatch-book"></i></span><div><h2>Brand colors</h2><p>Applied to actions, links, highlights, and generated emails.</p></div></div>
                <div class="form-grid form-grid--two">
                    <label class="field"><span>Primary color</span><div class="color-field"><input type="color" value="{{ $brandingSettings['primary_color'] }}" oninput="this.nextElementSibling.value=this.value"><input name="primary_color" value="{{ old('primary_color', $brandingSettings['primary_color']) }}" required></div></label>
                    <label class="field"><span>Secondary color</span><div class="color-field"><input type="color" value="{{ $brandingSettings['secondary_color'] }}" oninput="this.nextElementSibling.value=this.value"><input name="secondary_color" value="{{ old('secondary_color', $brandingSettings['secondary_color']) }}" required></div></label>
                </div>
                <div class="brand-preview" style="--preview-primary:{{ $brandingSettings['primary_color'] }};--preview-secondary:{{ $brandingSettings['secondary_color'] }}"><div><span class="brand-mark"><i data-lucide="layers-3"></i></span><strong>{{ $brandingSettings['name'] }}</strong></div><button type="button">Primary action</button><a href="#">Example link</a></div>
            </article>
            <article class="card settings-section"><div class="section-heading"><span class="section-icon"><i data-lucide="headphones"></i></span><div><h2>Support and legal</h2><p>Customer-facing contact and footer information.</p></div></div>
                <div class="form-grid form-grid--two">
                    <label class="field"><span>Support email</span><input type="email" name="support_email" value="{{ old('support_email', $brandingSettings['support_email']) }}"></label>
                    <label class="field"><span>Documentation URL</span><input type="url" name="documentation_url" value="{{ old('documentation_url', $brandingSettings['documentation_url']) }}"></label>
                    <label class="field field--wide"><span>Copyright</span><input name="copyright" value="{{ old('copyright', $brandingSettings['copyright']) }}"></label>
                </div>
            </article>
        </section>
        <aside class="settings-aside">
            <article class="card upload-card"><span class="section-icon"><i data-lucide="image"></i></span><h3>Logo assets</h3><p>Upload workspace-specific brand assets.</p><label class="button button--secondary button--full"><i data-lucide="upload"></i> Choose logo<input class="visually-hidden" type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label><label class="button button--secondary button--full"><i data-lucide="image"></i> Choose favicon<input class="visually-hidden" type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/x-icon"></label><small>PNG, JPG or WebP · max 2 MB</small></article>
            <article class="card help-card"><i data-lucide="info"></i><div><h3>Brand neutral by design</h3><p>These values flow through browser titles, emails, notifications, invoices, support, and API documentation.</p></div></article>
        </aside>
    </form>
</x-dashboard-layout>
