@props(['server'])

@php
    $destroysRemoteResources = $server->isByoCloud();
    $confirmationToken = $server->ip_address;
    $remoteResourceWarning = $server->provider?->value === 'digitalocean'
        ? 'its disks, volumes, associated snapshots, and reserved IPs'
        : 'its root disk, attached volumes, and primary IPs. Independent backups or snapshots may remain in Hetzner';
    $warning = $destroysRemoteResources
        ? "DANGER: This permanently destroys {$server->name} at {$server->ip_address}, {$remoteResourceWarning}. This cannot be undone. Type {$confirmationToken} to confirm."
        : "Remove {$server->name} from Uplary Cloud? Remote Docker data will not be deleted.";
@endphp

<form method="POST" action="{{ route('servers.destroy', $server) }}"
    @if($destroysRemoteResources)
        onsubmit="const expected = @js($confirmationToken); const entered = window.prompt(@js($warning), ''); if (entered !== expected) { if (entered !== null) window.alert('The IP address did not match. The server was not destroyed.'); return false; } this.elements.confirmation.value = entered; return true;"
    @else
        onsubmit="return window.confirm(@js($warning))"
    @endif
>
    @csrf
    @method('DELETE')
    @if($destroysRemoteResources)
        <input type="hidden" name="destroy_remote" value="1">
        <input type="hidden" name="confirmation" value="">
    @endif
    <button type="submit" class="is-danger">
        <i data-lucide="trash-2"></i>
        {{ $destroysRemoteResources ? 'Destroy server & remote data' : 'Destroy server' }}
    </button>
</form>
