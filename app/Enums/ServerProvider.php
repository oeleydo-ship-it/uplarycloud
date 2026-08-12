<?php

namespace App\Enums;

enum ServerProvider: string
{
    case Custom = 'custom';
    case Hetzner = 'hetzner';
    case DigitalOcean = 'digitalocean';
    case Vultr = 'vultr';
    case Aws = 'aws';
    case Linode = 'linode';
    case Ovh = 'ovh';

    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean', self::Aws => 'AWS', self::Ovh => 'OVH',
            default => ucfirst($this->value),
        };
    }
}
