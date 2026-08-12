<?php

namespace App\Support;

/**
 * POSIX shell quoting for commands sent to Linux hosts.
 *
 * escapeshellarg() follows the *control plane* platform: on Windows it wraps
 * arguments in double quotes and strips "%", "!" and '"', which silently
 * corrupts passwords and lets the remote bash expand "$" and backticks.
 */
class RemoteShell
{
    public static function quote(string|int|float|null $value): string
    {
        $value = (string) $value;

        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
