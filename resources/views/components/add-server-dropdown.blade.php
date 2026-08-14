@props([
    'managedServersEnabled' => false,
    'managedServersPaid' => false,
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
                <a href="{{ $managedServersPaid ? route('servers.create.managed') : route('billing.index') }}" class="add-server-dropdown__item" role="menuitem" @click="open = false">
                    <span class="add-server-dropdown__icon add-server-dropdown__icon--managed" aria-hidden="true"><i data-lucide="cloud-cog"></i></span>
                    <span class="add-server-dropdown__copy">
                        <strong>Add managed server</strong>
                        <small>{{ $managedServersPaid ? 'Provision a fully managed server from the platform catalog' : 'Payment required · Choose a paid plan to continue' }}</small>
                    </span>
                    @unless($managedServersPaid)<i data-lucide="lock-keyhole" aria-hidden="true"></i>@endunless
                </a>
            @endif
        </div>
    @endif
</div>
