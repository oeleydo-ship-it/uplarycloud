<?php

namespace App\Console\Commands;

use App\Services\Platform\ReadinessService;
use Illuminate\Console\Command;

class PlatformDoctor extends Command
{
    protected $signature = 'platform:doctor {--json : Output the report as JSON}';

    protected $description = 'Verify that the control plane is ready to serve traffic';

    public function handle(ReadinessService $readiness): int
    {
        $report = $readiness->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info('Uplary Cloud production readiness');
            $this->table(
                ['Check', 'Status', 'Latency'],
                collect($report['checks'])->map(fn (array $check, string $name): array => [
                    str($name)->replace('_', ' ')->title(),
                    strtoupper($check['status']),
                    number_format($check['latency_ms'], 2).' ms',
                ])->values()->all(),
            );
            $report['status'] === 'ready'
                ? $this->components->info('The platform is ready.')
                : $this->components->error('The platform is not ready.');
        }

        return $report['status'] === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
