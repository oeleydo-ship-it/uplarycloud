<x-admin-layout title="Branding">
    <div class="admin-heading">
        <div>
            <p>SUPERADMIN / BRANDING</p>
            <h1>Platform Branding</h1>
            <span>Logo, product identity, and default colors for the entire SaaS. Workspaces may override console colors only.</span>
        </div>
        <button form="branding-form" class="button button--primary" type="submit">
            <i data-lucide="save"></i> Save changes
        </button>
    </div>
    <form id="branding-form" class="admin-branding" method="post" enctype="multipart/form-data" action="{{ route('admin.branding.update') }}">
        @csrf @method('PUT')
        <div class="admin-branding-grid">
            <div class="admin-branding-main">
                <article class="card admin-form">
                    <div class="admin-card-head">
                        <div>
                            <h2>Product identity</h2>
                            <p>Names and copy shown on login, the tenant console, and emails.</p>
                        </div>
                        <span class="admin-pill admin-pill--purple">Global</span>
                    </div>
                    <div class="admin-form-grid">
                        <label>
                            <span>Application name</span>
                            <input name="name" value="{{ old('name', $brandingSettings['name']) }}" required>
                        </label>
                        <label>
                            <span>Short name</span>
                            <input name="short_name" value="{{ old('short_name', $brandingSettings['short_name']) }}" maxlength="8" required>
                        </label>
                        <label class="wide">
                            <span>Tagline</span>
                            <input name="tagline" value="{{ old('tagline', $brandingSettings['tagline']) }}">
                        </label>
                        <label>
                            <span>Company name</span>
                            <input name="company_name" value="{{ old('company_name', $brandingSettings['company_name']) }}">
                        </label>
                        <label>
                            <span>Website</span>
                            <input type="url" name="website" value="{{ old('website', $brandingSettings['website']) }}" placeholder="https://example.com">
                        </label>
                    </div>
                </article>

                <article class="card admin-form">
                    <div class="admin-card-head">
                        <div>
                            <h2>Brand colors</h2>
                            <p>Applied to actions, links, highlights, and generated emails.</p>
                        </div>
                    </div>
                    <div class="admin-form-grid">
                        <label>
                            <span>Primary color</span>
                            <div class="admin-color-field">
                                <input type="color" value="{{ old('primary_color', $brandingSettings['primary_color']) }}" oninput="this.nextElementSibling.value=this.value">
                                <input name="primary_color" value="{{ old('primary_color', $brandingSettings['primary_color']) }}" required>
                            </div>
                        </label>
                        <label>
                            <span>Secondary color</span>
                            <div class="admin-color-field">
                                <input type="color" value="{{ old('secondary_color', $brandingSettings['secondary_color']) }}" oninput="this.nextElementSibling.value=this.value">
                                <input name="secondary_color" value="{{ old('secondary_color', $brandingSettings['secondary_color']) }}" required>
                            </div>
                        </label>
                    </div>
                    <div class="admin-brand-preview" style="--preview-primary:{{ old('primary_color', $brandingSettings['primary_color']) }};--preview-secondary:{{ old('secondary_color', $brandingSettings['secondary_color']) }}">
                        <div>
                            <span class="admin-brand-mark">
                                @if ($brandingSettings['logo'])
                                    <img src="{{ Storage::url($brandingSettings['logo']) }}" alt="">
                                @else
                                    <i data-lucide="layers-3"></i>
                                @endif
                            </span>
                            <strong>{{ $brandingSettings['name'] }}</strong>
                        </div>
                        <button type="button">Primary action</button>
                        <a href="#">Example link</a>
                    </div>
                </article>

                <article class="card admin-form">
                    <div class="admin-card-head">
                        <div>
                            <h2>Support and legal</h2>
                            <p>Customer-facing contact and footer information.</p>
                        </div>
                    </div>
                    <div class="admin-form-grid">
                        <label>
                            <span>Support email</span>
                            <input type="email" name="support_email" value="{{ old('support_email', $brandingSettings['support_email']) }}">
                        </label>
                        <label>
                            <span>Documentation URL</span>
                            <input type="url" name="documentation_url" value="{{ old('documentation_url', $brandingSettings['documentation_url']) }}">
                        </label>
                        <label class="wide">
                            <span>Copyright</span>
                            <input name="copyright" value="{{ old('copyright', $brandingSettings['copyright']) }}">
                        </label>
                    </div>
                </article>
            </div>

            <aside class="admin-branding-aside">
                <article class="card admin-upload-card">
                    <span class="admin-upload-icon"><i data-lucide="image"></i></span>
                    <h3>Logo assets</h3>
                    <p>Shown on login, the tenant console, and browser tabs.</p>
                    @if ($brandingSettings['logo'])
                        <img class="admin-logo-preview" src="{{ Storage::url($brandingSettings['logo']) }}" alt="Current logo">
                    @endif
                    <label class="button button--secondary button--full">
                        <i data-lucide="upload"></i> Choose logo
                        <input class="visually-hidden" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                    </label>
                    <label class="button button--secondary button--full">
                        <i data-lucide="image"></i> Choose favicon
                        <input class="visually-hidden" type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/x-icon">
                    </label>
                    <small>PNG, JPG or WebP · max 2 MB</small>
                </article>
                <article class="card admin-help-card">
                    <i data-lucide="info"></i>
                    <div>
                        <h3>One brand for every tenant</h3>
                        <p>Name, logo, and default colors apply platform-wide. Each workspace can override primary and secondary colors for its own console only.</p>
                    </div>
                </article>
            </aside>
        </div>
    </form>
</x-admin-layout>
