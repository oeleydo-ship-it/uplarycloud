<?php

namespace App\Support;

use App\Models\Server;

class PlatformPaths
{
    public static function root(): string
    {
        return rtrim((string) config('infrastructure.remote_root', '/opt/uplary'), '/');
    }

    public static function apps(): string
    {
        return self::root().'/apps';
    }

    public static function backups(): string
    {
        return self::root().'/backups';
    }

    public static function builds(): string
    {
        return self::root().'/builds';
    }

    public static function keys(): string
    {
        return self::root().'/keys';
    }

    public static function monitoring(): string
    {
        return self::root().'/monitoring';
    }

    public static function traefik(): string
    {
        return self::root().'/traefik';
    }

    public static function ensureTreeCommand(?string $sshUsername): string
    {
        $user = trim((string) $sshUsername) ?: 'root';
        $command = self::installTreeCommand($user);

        if (strcasecmp($user, 'root') === 0) {
            return $command;
        }

        return 'sudo -n sh -c '.RemoteShell::quote($command);
    }

    public static function installTreeCommand(?string $sshUsername): string
    {
        $directories = self::directoryList();
        $quoted = implode(' ', array_map(
            fn (string $directory): string => RemoteShell::quote($directory),
            $directories,
        ));

        $user = trim((string) $sshUsername) ?: 'root';
        $command = 'set -e; install -d -m 0755 '.$quoted;
        if (strcasecmp($user, 'root') !== 0) {
            $command .= '; chown -R '.RemoteShell::quote($user.':'.$user).' '.RemoteShell::quote(self::root());
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    private static function directoryList(): array
    {
        return array_values(array_unique([
            self::root(),
            self::apps(),
            self::backups(),
            self::builds(),
            self::keys(),
            self::monitoring(),
            self::traefik(),
            rtrim((string) config('networking.proxy_dynamic_path', self::traefik().'/dynamic'), '/'),
        ]));
    }

    public static function ensureTreeCommandFor(Server $server): string
    {
        return self::ensureTreeCommand($server->ssh_username);
    }
}
