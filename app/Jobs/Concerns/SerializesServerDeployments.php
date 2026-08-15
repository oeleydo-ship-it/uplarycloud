<?php

namespace App\Jobs\Concerns;

use App\Models\ApplicationDeployment;
use App\Services\Deployments\DeploymentService;
use App\Support\ServerDeploymentLock;
use Illuminate\Contracts\Cache\Lock;

trait SerializesServerDeployments
{
    private ?Lock $serverDeploymentLock = null;

    protected function acquireServerDeploymentLock(ApplicationDeployment $deployment, DeploymentService $logs, int $seconds): bool
    {
        $lock = ServerDeploymentLock::tryAcquire((int) $deployment->server_id, $seconds);

        if ($lock !== null) {
            $this->serverDeploymentLock = $lock;

            return true;
        }

        $logs->log(
            $deployment,
            'info',
            'Another deployment is active on this server; waiting for it to finish before starting.'
        );
        $this->release(45);

        return false;
    }

    protected function releaseServerDeploymentLock(): void
    {
        $this->serverDeploymentLock?->release();
        $this->serverDeploymentLock = null;
    }
}
