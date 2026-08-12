<?php

namespace App\Enums;

enum ContainerStatus: string
{
    case Running = 'running'; case Stopped = 'stopped'; case Restarting = 'restarting';
    case Paused = 'paused'; case Unhealthy = 'unhealthy'; case Exited = 'exited'; case Created = 'created';
    public function label(): string
    {
        return match ($this) {
            self::Unhealthy => 'Unhealthy',
            default => ucfirst($this->value),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Running => 'success',
            self::Restarting, self::Created => 'running',
            self::Paused, self::Unhealthy => 'warning',
            default => 'failed',
        };
    }
}
