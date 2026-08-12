<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Deploying = 'deploying';
    case Running = 'running';
    case Failed = 'failed';
    case Stopped = 'stopped';
    case RollingBack = 'rolling_back';

    public function label(): string { return str($this->value)->replace('_', ' ')->title(); }
    public function tone(): string { return match ($this) { self::Running => 'success', self::Failed => 'danger', self::Queued, self::Deploying, self::RollingBack => 'running', default => 'warning' }; }
}
