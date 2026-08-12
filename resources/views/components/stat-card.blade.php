@props(['label', 'value', 'detail', 'icon', 'tone' => 'purple', 'href' => null])
<article class="stat-card">
    <span class="stat-icon stat-icon--{{ $tone }}"><i data-lucide="{{ $icon }}"></i></span>
    <div><span class="stat-label">{{ $label }}</span><strong class="stat-value">{{ $value }}</strong><small class="stat-detail">{{ $detail }}</small></div>
    @if($href)<a href="{{ $href }}" class="mini-action" aria-label="View {{ $label }}"><i data-lucide="chevron-right"></i></a>@endif
</article>
