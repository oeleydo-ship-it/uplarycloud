<?php

namespace App\Services\Platform;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class PlatformServiceManager
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return collect(config('platform_services.services', []))
            ->mapWithKeys(fn (array $service, string $key): array => [
                $key => array_merge($service, $this->status($key)),
            ])->all();
    }

    /** @return array{status: string, status_label: string, detail: string} */
    public function status(string $service): array
    {
        if (! config('platform_services.enabled')) {
            return $this->statusPayload('unavailable', 'Controls disabled', 'Enable platform service controls in the environment.');
        }

        try {
            $result = $this->run('status', $service);
        } catch (Throwable $exception) {
            report($exception);

            return $this->statusPayload('unavailable', 'Unavailable', 'Supervisor could not be reached from the web process.');
        }

        $output = trim($result->output().' '.$result->errorOutput());

        if (str_contains($output, 'RUNNING')) {
            return $this->statusPayload('running', 'Running', $this->cleanOutput($output));
        }

        if (Str::contains($output, ['STOPPED', 'EXITED'])) {
            return $this->statusPayload('stopped', 'Stopped', $this->cleanOutput($output));
        }

        if (Str::contains($output, ['FATAL', 'BACKOFF'])) {
            return $this->statusPayload('failed', 'Needs attention', $this->cleanOutput($output));
        }

        return $this->statusPayload('unavailable', 'Unavailable', $this->cleanOutput($output ?: 'Supervisor returned no service status.'));
    }

    /** @return array{success: bool, message: string} */
    public function control(string $service, string $action): array
    {
        if (! config('platform_services.enabled')) {
            return ['success' => false, 'message' => 'Platform service controls are disabled.'];
        }

        try {
            $result = $this->run($action, $service);
        } catch (Throwable $exception) {
            report($exception);

            return ['success' => false, 'message' => 'Supervisor could not be reached. Check its socket permissions and configured binary path.'];
        }

        $output = $this->cleanOutput(trim($result->output().' '.$result->errorOutput()));

        if (! $result->successful()) {
            return ['success' => false, 'message' => $output ?: 'Supervisor rejected the service action.'];
        }

        $name = config("platform_services.services.{$service}.name", Str::headline($service));

        return [
            'success' => true,
            'message' => "{$name} ".match ($action) {
                'start' => 'has been started.',
                'stop' => 'has been stopped. Queued and realtime work will pause until it is started again.',
                'restart' => 'has been restarted.',
            },
        ];
    }

    private function run(string $command, string $service): ProcessResult
    {
        $definition = config("platform_services.services.{$service}");

        if (! is_array($definition) || ! in_array($command, ['status', 'start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException('Unsupported platform service command.');
        }

        $arguments = [
            (string) config('platform_services.supervisorctl', 'supervisorctl'),
            $command,
            (string) $definition['program'],
        ];

        if (config('platform_services.use_sudo')) {
            array_unshift($arguments, (string) config('platform_services.sudo', 'sudo'), '-n');
        }

        return Process::timeout((int) config('platform_services.timeout', 10))->run($arguments);
    }

    /** @return array{status: string, status_label: string, detail: string} */
    private function statusPayload(string $status, string $label, string $detail): array
    {
        return ['status' => $status, 'status_label' => $label, 'detail' => $detail];
    }

    private function cleanOutput(string $output): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', strip_tags($output)) ?: '', 220);
    }
}
