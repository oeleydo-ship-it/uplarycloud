<?php

namespace App\Console\Commands;

use App\Services\Deployments\OrphanedDeploymentRecovery;
use Illuminate\Console\Command;

class RecoverOrphanedDeployments extends Command
{
    protected $signature = 'deployments:recover-orphaned
                            {--dry-run : List orphaned deployments without re-dispatching}
                            {--grace= : Override grace seconds before a queued deploy is considered orphaned}';

    protected $description = 'Re-dispatch queued deployments that have no matching Redis job';

    public function handle(OrphanedDeploymentRecovery $recovery): int
    {
        if ($this->option('grace') !== null) {
            config(['infrastructure.orphaned_deployment_grace_seconds' => (int) $this->option('grace')]);
        }

        $actions = $recovery->recover($this->option('dry-run'));

        if ($actions === []) {
            $this->components->info('No orphaned queued deployments found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'UUID', 'Name', 'Action'],
            collect($actions)->map(fn (array $row): array => [
                $row['id'],
                $row['uuid'],
                $row['name'],
                $row['action'],
            ])->all(),
        );

        $this->components->info(count($actions).' orphaned deployment(s) '.($this->option('dry-run') ? 'detected.' : 're-dispatched.'));

        return self::SUCCESS;
    }
}
