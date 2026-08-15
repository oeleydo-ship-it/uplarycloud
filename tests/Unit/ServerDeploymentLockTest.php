<?php

namespace Tests\Unit;

use App\Support\ServerDeploymentLock;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServerDeploymentLockTest extends TestCase
{
    public function test_only_one_active_deployment_lock_per_server(): void
    {
        $first = ServerDeploymentLock::tryAcquire(42, 60);
        $this->assertNotNull($first);

        $second = ServerDeploymentLock::tryAcquire(42, 60);
        $this->assertNull($second);

        $first->release();

        $third = ServerDeploymentLock::tryAcquire(42, 60);
        $this->assertNotNull($third);
        $third->release();
    }

    public function test_different_servers_can_deploy_in_parallel(): void
    {
        $serverA = ServerDeploymentLock::tryAcquire(1, 60);
        $serverB = ServerDeploymentLock::tryAcquire(2, 60);

        $this->assertNotNull($serverA);
        $this->assertNotNull($serverB);

        $serverA->release();
        $serverB->release();
    }
}
