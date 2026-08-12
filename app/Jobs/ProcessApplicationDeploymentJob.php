<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Events\DeploymentProgressed;
use App\Models\ActivityLog;
use App\Models\ApplicationDeployment;
use App\Services\Deployments\DeploymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessApplicationDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public array $backoff = [10, 60];

    public function __construct(public int $deploymentId, public int $tenantId, public ?int $userId = null)
    {
        $this->onQueue(config('infrastructure.queues.deployments'));
    }

    public function handle(DeploymentService $service): void
    {
        $lock = Cache::lock('process-application-deployment:'.$this->deploymentId, $this->timeout);

        if (! $lock->get()) {
            return;
        }

        try {
            $deployment = ApplicationDeployment::where('tenant_id', $this->tenantId)->findOrFail($this->deploymentId);

            if (in_array($deployment->status, [DeploymentStatus::Running, DeploymentStatus::RollingBack], true)) {
                return;
            }

            $deployment->update(['status' => DeploymentStatus::Deploying, 'started_at' => now(), 'last_error' => null]);
            $service->log($deployment, 'info', 'Starting deployment of '.$deployment->name.'.');
            // A re-run must not inherit "completed" ticks from the previous attempt.
            $deployment->steps()->update(['status' => 'pending', 'started_at' => null, 'completed_at' => null, 'error' => null]);
            $total = count(DeploymentService::STAGES);

            try {
                foreach (array_values(DeploymentService::STAGES) as $index => $name) {
                    $key = array_keys(DeploymentService::STAGES)[$index];
                    $step = $deployment->steps()->where('key', $key)->firstOrFail();
                    $step->update(['status' => 'running', 'started_at' => now()]);
                    $progress = (int) floor(($index / $total) * 100);
                    $deployment->update(['current_stage' => $key, 'progress' => $progress]);
                    $service->log($deployment, 'info', $name.'…');
                    event(new DeploymentProgressed($deployment->tenant_id, $deployment->uuid, 'deploying', $progress, $key));
                    $service->execute($deployment, $key);
                    $step->update(['status' => 'completed', 'completed_at' => now()]);
                    $service->log($deployment, 'success', $name.' completed.');
                }
                $deployment->update(['status' => DeploymentStatus::Running, 'progress' => 100, 'current_stage' => 'complete', 'completed_at' => now(), 'deployed_at' => now()]);
                ActivityLog::create(['tenant_id' => $deployment->tenant_id, 'user_id' => $this->userId, 'action' => 'deployment.completed', 'description' => $deployment->name.' deployed successfully', 'subject_type' => ApplicationDeployment::class, 'subject_id' => $deployment->id]);
                event(new DeploymentProgressed($deployment->tenant_id, $deployment->uuid, 'running', 100, 'complete'));
            } catch (Throwable $e) {
                $deployment->steps()->where('status', 'running')->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);
                // A failed run never owns a release, even if an earlier attempt created one.
                $deployment->releases()->where('is_current', true)->update(['is_current' => false, 'status' => 'failed']);
                $deployment->update(['status' => DeploymentStatus::Failed, 'last_error' => $e->getMessage(), 'completed_at' => now()]);
                $service->log($deployment, 'error', $e->getMessage());
                event(new DeploymentProgressed($deployment->tenant_id, $deployment->uuid, 'failed', $deployment->progress, $deployment->current_stage));
            }
        } finally {
            $lock->release();
        }
    }
}
