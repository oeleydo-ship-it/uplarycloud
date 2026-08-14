<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Throwable;

class EnsureRedis extends Command
{
    protected $signature = 'platform:ensure-redis';

    protected $description = 'Verify Redis or start the local Docker Redis service';

    public function handle(): int
    {
        if ($this->redisAvailable()) {
            $this->components->info('Redis is ready.');

            return self::SUCCESS;
        }

        $host = (string) config('database.redis.default.host');
        if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $this->components->error("Redis at {$host} is unavailable. Start it before running the application.");

            return self::FAILURE;
        }

        $this->components->task('Starting the Redis Docker service', function (): bool {
            try {
                return Process::timeout(90)
                    ->run(['docker', 'compose', 'up', '-d', 'redis'])
                    ->successful();
            } catch (Throwable) {
                return false;
            }
        });

        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(500_000);

            if ($this->redisAvailable()) {
                $this->components->info('Redis is ready.');

                return self::SUCCESS;
            }
        }

        $this->components->error('Redis could not be reached. Start Docker Desktop or provide a reachable REDIS_HOST.');

        return self::FAILURE;
    }

    private function redisAvailable(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
