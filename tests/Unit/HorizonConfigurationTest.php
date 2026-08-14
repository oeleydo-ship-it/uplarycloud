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

        $this->assertSame(
            [config('infrastructure.queues.provisioning')],
            config('horizon.defaults.supervisor-provisioning.queue'),
        );
        $this->assertGreaterThanOrEqual(1, config('horizon.defaults.supervisor-provisioning.minProcesses'));
        $this->assertArrayHasKey('*', config('horizon.environments'));

        $longestWorkerTimeout = collect(config('horizon.defaults'))->max('timeout');

        $this->assertGreaterThan(
            $longestWorkerTimeout,
            config('queue.connections.redis.retry_after'),
        );
    }
}
