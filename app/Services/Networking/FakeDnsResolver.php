<?php

namespace App\Services\Networking;

use App\Contracts\Networking\DnsResolverInterface;
use App\Models\Domain;

/**
 * Demo/test resolver. Public hostnames always use real DNS so production
 * misconfiguration (DNS_DRIVER=fake) cannot mark domains Verified without a lookup.
 */
class FakeDnsResolver implements DnsResolverInterface
{
    public function __construct(private readonly SystemDnsResolver $system) {}

    public function resolve(string $hostname): array
    {
        $hostname = rtrim(strtolower($hostname), '.');

        if ($this->isPublicHostname($hostname)) {
            return $this->system->resolve($hostname);
        }

        return Domain::where('hostname', $hostname)->pluck('expected_value')->filter()->values()->all();
    }

    private function isPublicHostname(string $hostname): bool
    {
        if ($hostname === '' || ! str_contains($hostname, '.')) {
            return false;
        }

        $reserved = ['.example', '.example.com', '.example.org', '.example.net', '.test', '.localhost', '.invalid', '.local'];

        foreach ($reserved as $suffix) {
            if (str_ends_with($hostname, $suffix) || $hostname === ltrim($suffix, '.')) {
                return false;
            }
        }

        return true;
    }
}
