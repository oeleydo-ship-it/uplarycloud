<?php

namespace App\Services\Networking;

use App\Contracts\Networking\DnsResolverInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

class SystemDnsResolver implements DnsResolverInterface
{
    public function resolve(string $hostname): array
    {
        $hostname = rtrim(strtolower($hostname), '.');
        if ($hostname === '') {
            return [];
        }

        // Always merge local + DoH. On some Windows/PHP builds dns_get_record
        // returns truncated/stale A records (e.g. 42.x instead of 142.x) while
        // public resolvers are correct — DoH-only-as-empty-fallback missed those.
        $values = array_merge(
            $this->resolveLocally($hostname),
            $this->resolveViaDoh($hostname),
        );

        return array_values(array_unique(array_filter(
            $values,
            fn (string $ip): bool => (bool) filter_var($ip, FILTER_VALIDATE_IP)
        )));
    }

    /** @return array<int, string> */
    private function resolveLocally(string $hostname): array
    {
        $values = [];
        $current = $hostname;
        $seen = [];

        for ($depth = 0; $depth < 5 && $current !== '' && ! isset($seen[$current]); $depth++) {
            $seen[$current] = true;
            $records = @dns_get_record($current, DNS_A | DNS_AAAA | DNS_CNAME) ?: [];
            $cname = null;

            foreach ($records as $record) {
                if (! empty($record['ip'])) {
                    $values[] = strtolower($record['ip']);
                }
                if (! empty($record['ipv6'])) {
                    $values[] = strtolower($record['ipv6']);
                }
                if (! empty($record['target'])) {
                    $cname = rtrim(strtolower($record['target']), '.');
                }
            }

            if ($values !== []) {
                break;
            }

            $current = $cname ?? '';
        }

        return $values;
    }

    /**
     * Public DoH lookup — used always (merged), not only when local DNS is empty.
     *
     * @return array<int, string>
     */
    private function resolveViaDoh(string $hostname): array
    {
        $values = [];
        $endpoints = [
            'https://cloudflare-dns.com/dns-query',
            'https://dns.google/resolve',
        ];

        foreach ($endpoints as $endpoint) {
            foreach (['A', 'AAAA'] as $type) {
                try {
                    $response = Http::timeout(5)
                        ->accept('application/dns-json')
                        ->get($endpoint, [
                            'name' => $hostname,
                            'type' => $type,
                        ]);

                    if (! $response->successful()) {
                        continue;
                    }

                    foreach ($response->json('Answer') ?? [] as $answer) {
                        $data = isset($answer['data']) ? rtrim(strtolower((string) $answer['data']), '.') : '';
                        if ($data === '') {
                            continue;
                        }
                        if ($type === 'A' && filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $values[] = $data;
                        }
                        if ($type === 'AAAA' && filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                            $values[] = $data;
                        }
                    }
                } catch (Throwable) {
                    // Try the next endpoint / type.
                }
            }

            if ($values !== []) {
                break;
            }
        }

        return $values;
    }
}
