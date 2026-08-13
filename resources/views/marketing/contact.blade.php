<x-marketing-layout title="Contact" description="Talk to the Uplary Cloud team about plans, onboarding, or support.">
    <div class="mkt-wrap mkt-page">
        <span class="mkt-kicker">Contact</span>
        <h1 class="mkt-title">Tell us what you need.</h1>
        <p class="mkt-lead">Sales, onboarding, and general questions land here. If you already have a workspace, the in-app support inbox is faster for production issues.</p>

        @if(session('success'))
            <div class="mkt-success" style="margin-top:24px"><i data-lucide="circle-check"></i>{{ session('success') }}</div>
        @endif

        <form method="POST" action="{{ route('marketing.contact.store') }}" class="mkt-form" style="margin-top:28px">
            @csrf
            <div class="form-grid form-grid--two">
                <label class="field">
                    <span>Name</span>
                    <input type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
                    @error('name')<small class="field-error">{{ $message }}</small>@enderror
                </label>
                <label class="field">
                    <span>Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
                    @error('email')<small class="field-error">{{ $message }}</small>@enderror
                </label>
                <label class="field">
                    <span>Company <small style="font-weight:500;color:var(--muted)">(optional)</small></span>
                    <input type="text" name="company" value="{{ old('company') }}" autocomplete="organization">
                    @error('company')<small class="field-error">{{ $message }}</small>@enderror
                </label>
                <label class="field">
                    <span>Topic</span>
                    <select name="topic">
                        @foreach(['general' => 'General', 'sales' => 'Sales', 'support' => 'Support', 'partnership' => 'Partnership'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('topic', 'general') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('topic')<small class="field-error">{{ $message }}</small>@enderror
                </label>
                <label class="field field--wide">
                    <span>Subject</span>
                    <input type="text" name="subject" value="{{ old('subject') }}" required>
                    @error('subject')<small class="field-error">{{ $message }}</small>@enderror
                </label>
                <label class="field field--wide">
                    <span>Message</span>
                    <textarea name="message" required>{{ old('message') }}</textarea>
                    @error('message')<small class="field-error">{{ $message }}</small>@enderror
                </label>
            </div>
            <button class="button button--primary" type="submit">Send message</button>
        </form>
    </div>
</x-marketing-layout>
