<?php

namespace App\Enums;

enum ServerStatus: string
{
    case Pending = 'pending';
    case Testing = 'testing';
    case Provisioning = 'provisioning';
    case Online = 'online';
    case Offline = 'offline';
    case Failed = 'failed';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Online => 'success', self::Provisioning, self::Testing => 'running',
            self::Pending, self::Maintenance => 'warning', self::Failed, self::Offline => 'failed',
        };
    }
}
