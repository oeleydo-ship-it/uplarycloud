<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Events\DeploymentProgressed;
use App\Jobs\Concerns\SerializesServerDeployments;
use App\Models\ActivityLog;
use App\Models\ApplicationDeployment;
use App\Services\Deployments\DeploymentService;
use App\Services\Deployments\WebApplicationDeploymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWebApplicationDeploymentJob implements ShouldQueue
{
    use Queueable;
    use SerializesServerDeployments;

    public int $tries = 2;

    public int $timeout = 3600;

    public array $backoff = [20, 120];

    public function __construct(public int $deploymentId, public int $tenantId, public ?int $userId = null)
    {
        $this->onQueue(config('infrastructure.queues.deployments'));
    }

    public function handle(WebApplicationDeploymentService $web, DeploymentService $logs): void
    {
        $d = ApplicationDeployment::where('tenant_id', $this->tenantId)->findOrFail($this->deploymentId);

        if (! $this->acquireServerDeploymentLock($d, $logs, $this->timeout + 120)) {
            return;
        }

        try {
            $d->update(['status' => DeploymentStatus::Deploying, 'build_status' => 'building', 'started_at' => now(), 'last_error' => null]);
            $logs->log($d, 'info', 'Starting '.$d->framework.' build from '.$d->git_provider.'.');
            $d->steps()->update(['status' => 'pending', 'started_at' => null, 'completed_at' => null, 'error' => null]);
            $total = count(WebApplicationDeploymentService::STAGES);

            try {
                foreach (array_values(WebApplicationDeploymentService::STAGES) as $index => $name) {
                    $key = array_keys(WebApplicationDeploymentService::STAGES)[$index];
                    $step = $d->steps()->where('key', $key)->firstOrFail();
                    $progress = (int) floor(($index / $total) * 100);
                    $step->update(['status' => 'running', 'started_at' => now()]);
                    $d->update(['current_stage' => $key, 'progress' => $progress]);
                    $logs->log($d, 'info', $name.'…');
                    event(new DeploymentProgressed($d->tenant_id, $d->uuid, 'deploying', $progress, $key));
                    $web->execute($d, $key);
                    $step->update(['status' => 'completed', 'completed_at' => now()]);
                    $logs->log($d, 'success', $name.' completed.');
                }

                $d->update(['status' => DeploymentStatus::Running, 'build_status' => 'successful', 'progress' => 100, 'current_stage' => 'complete', 'completed_at' => now(), 'deployed_at' => now()]);
                ActivityLog::create(['tenant_id' => $d->tenant_id, 'user_id' => $this->userId, 'action' => 'web.deployment.completed', 'description' => $d->name.' built and deployed successfully', 'subject_type' => ApplicationDeployment::class, 'subject_id' => $d->id]);
                event(new DeploymentProgressed($d->tenant_id, $d->uuid, 'running', 100, 'complete'));
            } catch (Throwable $e) {
                $d->steps()->where('status', 'running')->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);
                $d->releases()->where('is_current', true)->update(['is_current' => false, 'status' => 'failed']);
                $d->update(['status' => DeploymentStatus::Failed, 'build_status' => 'failed', 'last_error' => $e->getMessage(), 'completed_at' => now()]);
                $logs->log($d, 'error', $e->getMessage());
                event(new DeploymentProgressed($d->tenant_id, $d->uuid, 'failed', $d->progress, $d->current_stage));
            }
        } finally {
            $this->releaseServerDeploymentLock();
        }
    }
}
