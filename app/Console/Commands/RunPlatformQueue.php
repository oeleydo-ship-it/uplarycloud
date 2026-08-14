<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RunPlatformQueue extends Command
{
    protected $signature = 'platform:queue';

    protected $description = 'Run Horizon, with a Windows-compatible Redis queue fallback for development';

    public function handle(): int
    {
        if (extension_loaded('pcntl') && extension_loaded('posix')) {
            $this->components->info('Starting Laravel Horizon.');

            return $this->call('horizon');
        }

        if (! app()->environment('local', 'testing')) {
            $this->components->error('Horizon requires the pcntl and posix PHP extensions in production.');

            return self::FAILURE;
        }

        $queues = collect(config('horizon.defaults'))
            ->flatMap(fn (array $supervisor): array => $supervisor['queue'])
            ->unique()
            ->implode(',');

        $this->components->warn('Horizon requires Linux process signals; using a Redis queue worker for native Windows development.');

        return $this->call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queues,
            '--sleep' => 1,
            '--tries' => 20,
            '--backoff' => 5,
            '--timeout' => 1260,
        ]);
    }
}
