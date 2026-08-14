@props(['server', 'hasAttachedApps' => false])

@can('operate', $server)
    @unless($server->isProvisioningIncomplete())
        <form method="POST" action="{{ route('servers.power', $server) }}" onsubmit="return confirm(@js('Shut down '.$server->name.'? Running containers will stop.'))">
            @csrf
            <input type="hidden" name="action" value="shutdown">
            <button type="submit"><i data-lucide="power"></i> Shut down</button>
        </form>
        <form method="POST" action="{{ route('servers.power', $server) }}" onsubmit="return confirm(@js('Reboot '.$server->name.' now?'))">
            @csrf
            <input type="hidden" name="action" value="reboot">
            <button type="submit"><i data-lucide="rotate-cw"></i> Reboot</button>
        </form>
        @if($hasAttachedApps)
            <span class="server-destroy-blocked" title="Remove attached applications first">
                <i data-lucide="hard-drive-download"></i> Remove applications to restore
            </span>
        @else
            <form method="POST" action="{{ route('servers.power', $server) }}" onsubmit="return confirm(@js('Restore '.$server->name.' to a clean Linux OS and run platform provisioning again? All containers, volumes, and apps on this server will be removed.'))">
                @csrf
                <input type="hidden" name="action" value="restore">
                <button type="submit" class="is-danger"><i data-lucide="hard-drive-download"></i> Restore clean OS &amp; reprovision</button>
            </form>
        @endif
    @endunless
@endcan
