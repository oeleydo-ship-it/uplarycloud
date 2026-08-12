<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';
    case Viewer = 'viewer';
    case Billing = 'billing';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function canManageWorkspace(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
