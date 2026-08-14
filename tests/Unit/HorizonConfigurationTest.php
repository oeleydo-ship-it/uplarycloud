<?php

namespace Tests\Unit;

use Tests\TestCase;

class HorizonConfigurationTest extends TestCase
{
    public function test_horizon_supervises_every_application_queue(): void
    {
        $supervisedQueues = collect(config('horizon.defaults'))
            ->flatMap(fn (array $supervisor): array => $supervisor['queue'])
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            'deployments',
            'infrastructure',
            'provisioning',
            'networking',
            'backups',
            'notifications',
            'monitoring',
            'default',
        ], $supervisedQueues);

        $this->assertContains(
            config('infrastructure.queues.infrastructure'),
            config('horizon.defaults.supervisor-infrastructure.queue'),
        );

        $longestWorkerTimeout = collect(config('horizon.defaults'))->max('timeout');

        $this->assertGreaterThan(
            $longestWorkerTimeout,
            config('queue.connections.redis.retry_after'),
        );
    }
}
