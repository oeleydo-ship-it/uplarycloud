@props([
    'managedServersEnabled' => false,
    'empty' => false,
    'quotaReached' => false,
])

<div
    class="add-server-dropdown{{ $empty ? ' add-server-dropdown--empty' : '' }}"
    x-data="{ open: false }"
    @keydown.escape.window="open = false"
>
    @if($quotaReached)
        <a href="{{ route('billing.index') }}" class="button button--primary">
            <i data-lucide="sparkles"></i>
            Upgrade to add servers
        </a>
    @else
        <button
            type="button"
            class="button button--primary add-server-dropdown__trigger"
            @click="open = !open"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
        >
            <i data-lucide="plus"></i>
            Add Server
            <i data-lucide="chevron-down" class="add-server-dropdown__chevron" :class="open && 'is-open'"></i>
        </button>
        <div
            class="add-server-dropdown__menu"
            role="menu"
            x-cloak
            x-show="open"
            x-transition.origin.top.right
            @click.outside="open = false"
        >
            <a href="{{ route('servers.create') }}" class="add-server-dropdown__item" role="menuitem" @click="open = false">
                <span class="add-server-dropdown__icon" aria-hidden="true"><i data-lucide="server"></i></span>
                <span class="add-server-dropdown__copy">
                    <strong>Add custom own server</strong>
                    <small>Connect over SSH or provision with your Cloud API</small>
                </span>
            </a>
            @if($managedServersEnabled)
                <a href="{{ route('servers.create.managed') }}" class="add-server-dropdown__item" role="menuitem" @click="open = false">
                    <span class="add-server-dropdown__icon add-server-dropdown__icon--managed" aria-hidden="true"><i data-lucide="cloud-cog"></i></span>
                    <span class="add-server-dropdown__copy">
                        <strong>Add managed server</strong>
                        <small>Order from platform-managed DigitalOcean or Hetzner</small>
                    </span>
                </a>
            @endif
        </div>
    @endif
</div>
