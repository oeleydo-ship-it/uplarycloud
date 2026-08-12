<?php

namespace App\Contracts\Networking;

interface DnsResolverInterface
{
    /** @return array<int, string> */
    public function resolve(string $hostname): array;
}
