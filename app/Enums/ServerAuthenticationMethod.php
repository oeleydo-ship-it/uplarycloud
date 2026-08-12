<?php

namespace App\Enums;

enum ServerAuthenticationMethod: string
{
    case SshKey = 'ssh_key';
    case Password = 'password';

    public function label(): string
    {
        return $this === self::SshKey ? 'SSH key' : 'Password';
    }
}
