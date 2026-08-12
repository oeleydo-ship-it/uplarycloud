<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReadinessService
{
    public function report(): array
    {
        $checks = [
            'application_key' => $this->check(fn () => filled(config('app.key'))),
            'database' => $this->check(function (): bool {
                DB::connection()->select('select 1');

                return true;
            }),
            'migrations' => $this->check(fn (): bool => Schema::hasTable('migrations')),
            'cache' => $this->check(function (): bool {
                $key = 'platform-readiness:'.bin2hex(random_bytes(8));
                $value = bin2hex(random_bytes(8));
                Cache::put($key, $value, 10);
                $stored = Cache::get($key);
                Cache::forget($key);

                return hash_equals($value, (string) $stored);
            }),
            'storage' => $this->check(fn (): bool => is_dir(storage_path()) && is_writable(storage_path())),
        ];

        return [
            'status' => collect($checks)->every(fn (array $check): bool => $check['status'] === 'pass') ? 'ready' : 'not_ready',
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function check(callable $probe): array
    {
        $startedAt = hrtime(true);

        try {
            $passed = $probe() === true;

            return [
                'status' => $passed ? 'pass' : 'fail',
                'latency_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ];
        } catch (Throwable) {
            return [
                'status' => 'fail',
                'latency_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ];
        }
    }
}
