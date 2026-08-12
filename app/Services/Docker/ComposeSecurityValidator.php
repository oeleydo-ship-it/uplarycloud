<?php

namespace App\Services\Docker;

use Illuminate\Validation\ValidationException;

class ComposeSecurityValidator
{
    private const BLOCKED = [
        '/\bprivileged\s*:\s*true\b/i' => 'Privileged containers are not allowed.',
        '/\bnetwork_mode\s*:\s*host\b/i' => 'Host networking is not allowed.',
        '/\bpid\s*:\s*host\b/i' => 'Host PID access is not allowed.',
        '/\bdevices\s*:/i' => 'Host device access requires platform approval.',
        '#(?:/var/run/)?docker\.sock#i' => 'Docker socket mounts are not allowed.',
        '#(?:^|[\s\-:])/(?:\s*):#m' => 'Host root filesystem mounts are not allowed.',
        '/(?:3306|5432|6379)\s*:\s*(?:3306|5432|6379)/' => 'Database ports may not be publicly exposed by default.',
    ];
    public function validate(string $compose): void
    {
        if (strlen($compose) > 500_000) throw ValidationException::withMessages(['compose_content'=>'Compose content is too large.']);
        foreach (self::BLOCKED as $pattern => $message) if (preg_match($pattern, $compose)) throw ValidationException::withMessages(['compose_content'=>$message]);
        if (! preg_match('/(?:^|\n)services\s*:/', $compose)) throw ValidationException::withMessages(['compose_content'=>'A services section is required.']);
    }
}
