<?php

namespace App\Services\Networking;

use App\Support\PlatformSettings;
use RuntimeException;

class AcmeEmailResolver
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function resolve(): string
    {
        $candidates = [
            $this->settings->get('general', 'acme_email'),
            config('networking.acme_email'),
            $this->settings->get('general', 'support_email'),
            config('mail.from.address'),
        ];

        foreach ($candidates as $candidate) {
            $email = trim((string) $candidate);
            $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

            if (filter_var($email, FILTER_VALIDATE_EMAIL)
                && ! in_array($domain, ['example.com', 'example.org', 'example.net', 'localhost'], true)) {
                return $email;
            }
        }

        throw new RuntimeException('Set a real Let\'s Encrypt email under Superadmin > General Settings before issuing certificates.');
    }
}
