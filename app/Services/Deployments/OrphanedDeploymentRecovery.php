<?php

namespace App\Services\Deployments;

use App\Enums\DeploymentStatus;
use App\Events\DeploymentProgressed;
use App\Jobs\ProcessApplicationDeploymentJob;
use App\Jobs\ProcessWebApplicationDeploymentJob;
use App\Models\ApplicationDeployment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class OrphanedDeploymentRecovery
{
    /**
     * Seconds a deployment may remain queued before we treat a missing Redis job as orphaned.
     */
    public function graceSeconds(): int
    {
        return max(15, (int) config('infrastructure.orphaned_deployment_grace_seconds', 45));
    }

    /**
     * Re-dispatch queued deployments that have no matching Redis job.
     *
     * @return list<array{id:int,uuid:string,name:string,action:string}>
     */
    public function recover(bool $dryRun = false): array
    {
        $actions = [];
        $cutoff = now()->subSeconds($this->graceSeconds());

        $deployments = ApplicationDeployment::query()
            ->where('status', DeploymentStatus::Queued)
            ->where('progress', 0)
            ->where('created_at', '<=', $cutoff)
            ->where(function ($query): void {
                $query->whereNull('updated_at')->orWhere('updated_at', '<=', now()->subSeconds($this->graceSeconds()));
            })
            ->orderBy('id')
            ->get();

        foreach ($deployments as $deployment) {
            if ($this->hasQueuedJob($deployment)) {
                continue;
            }

            $action = $dryRun ? 'would_redispatch' : 'redispatched';
            $actions[] = [
                'id' => $deployment->id,
                'uuid' => $deployment->uuid,
                'name' => $deployment->name,
                'action' => $action,
            ];

            if ($dryRun) {
                continue;
            }

            $this->redispatch($deployment);
        }

        return $actions;
    }

    /**
     * Recover a single deployment when the show/status endpoints observe it still queued.
     * Safe to call on every poll: no-ops when the Redis job exists or grace has not elapsed.
     */
    public function recoverIfOrphaned(ApplicationDeployment $deployment): bool
    {
        $deployment->refresh();

        if ($deployment->status !== DeploymentStatus::Queued || (int) $deployment->progress !== 0) {
            return false;
        }

        $grace = $this->graceSeconds();
        $oldest = $deployment->updated_at ?? $deployment->created_at;
        if ($oldest !== null && $oldest->gt(now()->subSeconds($grace))) {
            return false;
        }

        if ($this->hasQueuedJob($deployment)) {
            return false;
        }

        $this->redispatch($deployment);

        return true;
    }

    public function hasQueuedJob(ApplicationDeployment $deployment): bool
    {
        if (config('queue.default') !== 'redis') {
            return false;
        }

        $queue = (string) config('infrastructure.queues.deployments', 'deployments');

        try {
            return $this->queuePayloadMentionsDeployment($queue, $deployment);
        } catch (Throwable $e) {
            Log::warning('Unable to inspect Redis for orphaned deployment recovery.', [
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage(),
            ]);

            // Fail closed: do not re-dispatch when Redis cannot be inspected.
            return true;
        }
    }

    public function redispatch(ApplicationDeployment $deployment): void
    {
        $deployment->update([
            'status' => DeploymentStatus::Queued,
            'progress' => 0,
            'current_stage' => 'queued',
            'last_error' => null,
        ]);

        if (in_array($deployment->deployment_type, ['web', 'git'], true)) {
            ProcessWebApplicationDeploymentJob::dispatch($deployment->id, $deployment->tenant_id, $deployment->created_by);
        } else {
            ProcessApplicationDeploymentJob::dispatch($deployment->id, $deployment->tenant_id, $deployment->created_by);
        }

        event(new DeploymentProgressed($deployment->tenant_id, $deployment->uuid, 'queued', 0, 'queued'));

        Log::info('Re-dispatched orphaned queued deployment.', [
            'deployment_id' => $deployment->id,
            'uuid' => $deployment->uuid,
            'type' => $deployment->deployment_type,
        ]);
    }

    private function queuePayloadMentionsDeployment(string $queue, ApplicationDeployment $deployment): bool
    {
        $id = (int) $deployment->id;
        $needles = [
            'deploymentId";i:'.$id.';',
            'deploymentId\";i:'.$id.';',
            '"deploymentId":'.$id,
            's:'.strlen((string) $id).':"'.$id.'"',
            $deployment->uuid,
        ];

        foreach ($this->queuePayloads($queue) as $payload) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($payload, $needle)) {
                    return true;
                }
            }

            $decoded = json_decode($payload, true);
            $command = is_array($decoded) ? (string) ($decoded['data']['command'] ?? '') : '';
            if ($command !== '' && (str_contains($command, 'deploymentId";i:'.$id.';') || str_contains($command, '"deploymentId":'.$id))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function queuePayloads(string $queue): array
    {
        $redis = Redis::connection();
        $payloads = [];

        foreach ($redis->lrange('queues:'.$queue, 0, -1) ?: [] as $item) {
            $payloads[] = (string) $item;
        }

        foreach ($redis->zrange('queues:'.$queue.':delayed', 0, -1) ?: [] as $item) {
            $payloads[] = (string) $item;
        }

        // Only count recently reserved jobs. Stale reserved entries (worker crash before
        // status flipped to deploying) would otherwise block recovery until retry_after (~16m).
        $staleAfter = max(90, (int) config('infrastructure.orphaned_reserved_job_seconds', 120));
        $freshReservedSince = now()->subSeconds($staleAfter)->getTimestamp();
        foreach ($redis->zrangebyscore('queues:'.$queue.':reserved', (string) $freshReservedSince, '+inf') ?: [] as $item) {
            $payloads[] = (string) $item;
        }

        return $payloads;
    }
}
