@props(['application', 'size' => 'md', 'fallbackIcon' => 'box'])

@php
    $accent = $application?->accent ?? '#6c4cf5';
    $logoUrl = $application?->logoUrl();
    $icon = $application?->icon ?? $fallbackIcon;
    $name = $application?->name ?? 'Application';
    $sizeClass = match ($size) {
        'sm' => 'app-icon--sm',
        'lg' => 'app-icon--lg',
        default => 'app-icon--md',
    };
@endphp

<span {{ $attributes->merge(['class' => "app-icon {$sizeClass}".($logoUrl ? ' app-icon--logo' : '')]) }} style="--app-accent: {{ $accent }}">
    @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $name }} logo" loading="lazy" decoding="async">
    @else
        <i data-lucide="{{ $icon }}"></i>
    @endif
</span>
