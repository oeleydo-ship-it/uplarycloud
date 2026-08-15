<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class ServerDeploymentLock
{
    public static function key(int $serverId): string
    {
        return 'server-deployment:'.$serverId;
    }

    public static function tryAcquire(int $serverId, int $seconds): ?Lock
    {
        $lock = Cache::lock(self::key($serverId), $seconds);

        return $lock->get() ? $lock : null;
    }
}
