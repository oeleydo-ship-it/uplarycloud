<?php

namespace App\Services\Servers;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerCredential;

class ServerConnectionTester
{
    public const MIN_CPU_CORES = 1;

    public const MIN_MEMORY_MB = 1900;

    public const MIN_ADVERTISED_MEMORY_MB = 2048;

    public const MIN_DISK_GB = 15;

    /**
     * DigitalOcean 2 GB droplets often report ~1970–1990 MiB MemTotal after
     * firmware reserve. Trust the cloud size when it is at least 2 GB advertised;
     * keep the 1900 MiB floor so 1 GB droplets still fail.
     */
    public static function resolveMemoryMb(int $measuredMb, int $advertisedMb): int
    {
        if ($advertisedMb >= self::MIN_ADVERTISED_MEMORY_MB && $measuredMb < self::MIN_MEMORY_MB) {
            return $advertisedMb;
        }

        return $measuredMb;
    }

    public function __construct(private readonly ServerExecutorInterface $executor) {}

    public function test(Server $server): array
    {
        $server->update(['status' => ServerStatus::Testing]);
        try {
            $result = $this->evaluate($server);
            if ($result['success']) {
                $server->update(array_merge(
                    ['status' => ServerStatus::Pending],
                    array_intersect_key($result['system'], array_flip(['cpu_cores', 'memory_mb', 'disk_gb', 'operating_system']))
                ));
            } else {
                $server->update(['status' => ServerStatus::Pending, 'failure_reason' => null]);
            }

            return $result;
        } catch (\Throwable $exception) {
            $server->update(['status' => ServerStatus::Failed, 'failure_reason' => 'Connection test failed.']);
            report($exception);

            return $this->failureFromException($exception);
        }
    }

    /**
     * Validate connection credentials from the Add Server wizard without persisting a Server.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string, driver: string, checks: list<array<string, mixed>>, system: array<string, mixed>}
     */
    public function validatePayload(array $payload): array
    {
        $server = $this->ephemeralServer($payload);

        try {
            return $this->evaluate($server);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->failureFromException($exception);
        }
    }

    /**
     * @return array{success: bool, message: string, driver: string, checks: list<array<string, mixed>>, system: array<string, mixed>}
     */
    private function evaluate(Server $server): array
    {
        $result = $this->executor->test($server);

        if (! ($result['success'] ?? false)) {
            return $this->failure(
                $result['message'] ?? 'The server connection could not be verified.',
                $result['message'] ?? 'SSH authentication or host reachability failed.'
            );
        }

        $system = $result['system'] ?? [];
        $checks = $this->buildChecks($system, $server);
        $failed = collect($checks)->first(fn (array $check) => ($check['blocking'] ?? true) && ! $check['passed']);

        if ($failed) {
            return $this->decorate([
                'success' => false,
                'message' => $failed['message'] ?? 'The server does not meet the platform requirements.',
                'checks' => $checks,
                'system' => $system,
            ]);
        }

        return $this->decorate([
            'success' => true,
            'message' => $result['message'] ?? 'Connection verified successfully.',
            'checks' => $checks,
            'system' => $system,
        ]);
    }

    /**
     * @param  array{success: bool, message: string, checks: list<array<string, mixed>>, system: array<string, mixed>}  $result
     * @return array{success: bool, message: string, driver: string, checks: list<array<string, mixed>>, system: array<string, mixed>}
     */
    private function decorate(array $result): array
    {
        $driver = $this->driver();
        $result['driver'] = $driver;

        if ($driver === 'fake') {
            $result['message'] = ($result['success'] ?? false)
                ? 'Fake driver — simulated pre-check passed (not a live SSH connection).'
                : ($result['message'] ?? 'Fake driver — simulated pre-check failed.');
        }

        return $result;
    }

    private function driver(): string
    {
        return config('infrastructure.driver') === 'ssh' ? 'ssh' : 'fake';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ephemeralServer(array $payload): Server
    {
        $server = new Server([
            'name' => 'connection-precheck',
            'ip_address' => $payload['ip_address'],
            'operating_system' => $payload['operating_system'],
            'ssh_port' => (int) $payload['ssh_port'],
            'ssh_username' => $payload['ssh_username'],
            'authentication_method' => $payload['authentication_method'],
            'connection_timeout' => (int) $payload['connection_timeout'],
            'install_docker' => (bool) ($payload['install_docker'] ?? true),
        ]);

        $credential = new ServerCredential([
            'private_key' => $payload['private_key'] ?? null,
            'password' => $payload['password'] ?? null,
            'passphrase' => $payload['passphrase'] ?? null,
        ]);

        $server->setRelation('credential', $credential);

        return $server;
    }

    /**
     * @param  array<string, mixed>  $system
     * @return list<array<string, mixed>>
     */
    private function buildChecks(array $system, Server $server): array
    {
        $os = (string) ($system['operating_system'] ?? '');
        $cpu = (int) ($system['cpu_cores'] ?? 0);
        $memory = (int) ($system['memory_mb'] ?? 0);
        $disk = (int) ($system['disk_gb'] ?? 0);
        $dockerAvailable = (bool) ($system['docker_available'] ?? false);
        $dockerVersion = (string) ($system['docker_version'] ?? '');
        $supportedOs = in_array($os, config('infrastructure.supported_operating_systems'), true)
            || in_array($server->operating_system, config('infrastructure.supported_operating_systems'), true);

        $memory = self::resolveMemoryMb($memory, (int) $server->memory_mb);
        $cpuPass = $cpu >= self::MIN_CPU_CORES;
        $memoryPass = $memory >= self::MIN_MEMORY_MB;
        $diskPass = $disk >= self::MIN_DISK_GB;

        $dockerMessage = $dockerAvailable
            ? ($dockerVersion !== '' ? $dockerVersion : 'Docker is available on the host.')
            : 'Docker is not installed yet and will be set up during provisioning.';

        return [
            [
                'key' => 'ssh',
                'label' => 'Host reachable / SSH access',
                'passed' => true,
                'blocking' => true,
                'message' => 'SSH authentication succeeded.',
            ],
            [
                'key' => 'sudo',
                'label' => 'Root or passwordless sudo',
                'passed' => true,
                'blocking' => true,
                'message' => 'Privilege check passed.',
            ],
            [
                'key' => 'os',
                'label' => 'Supported operating system',
                'passed' => $supportedOs,
                'blocking' => true,
                'message' => $supportedOs
                    ? ($os !== '' ? $os : (string) $server->operating_system)
                    : 'Only Ubuntu 22.04 / 24.04 and Debian 12 are supported.',
            ],
            [
                'key' => 'cpu',
                'label' => 'Minimum 1 CPU',
                'passed' => $cpuPass,
                'blocking' => true,
                'message' => $cpuPass
                    ? $cpu.' CPU core'.($cpu === 1 ? '' : 's')
                    : 'At least 1 CPU core is required.',
            ],
            [
                'key' => 'memory',
                'label' => 'Minimum 2 GB RAM',
                'passed' => $memoryPass,
                'blocking' => true,
                'message' => $memoryPass
                    ? round($memory / 1024, 1).' GB RAM'
                    : 'At least 2 GB of RAM is required.',
            ],
            [
                'key' => 'disk',
                'label' => 'Minimum 15 GB disk',
                'passed' => $diskPass,
                'blocking' => true,
                'message' => $diskPass
                    ? $disk.' GB disk'
                    : 'At least 15 GB of disk space is required.',
            ],
            [
                'key' => 'docker',
                'label' => 'Docker availability',
                'passed' => true,
                'blocking' => false,
                'message' => $dockerMessage,
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string, checks: list<array<string, mixed>>, system: array<string, mixed>}
     */
    private function failureFromException(\Throwable $exception): array
    {
        $detail = $this->safeMessage($exception);
        $lower = strtolower($detail);

        if (str_contains($lower, 'operating system') || str_contains($lower, 'not supported')) {
            return $this->partialFailure('os', $detail, 'SSH authentication succeeded.');
        }

        if (str_contains($lower, 'sudo') || str_contains($lower, 'root')) {
            return $this->partialFailure('sudo', $detail, 'SSH authentication succeeded.');
        }

        return $this->failure(
            'The server connection could not be verified.',
            $detail
        );
    }

    /**
     * @return array{success: bool, message: string, driver: string, checks: list<array<string, mixed>>, system: array<string, mixed>}
     */
    private function partialFailure(string $failedKey, string $detail, string $sshMessage): array
    {
        $checks = [
            [
                'key' => 'ssh',
                'label' => 'Host reachable / SSH access',
                'passed' => true,
                'blocking' => true,
                'message' => $sshMessage,
            ],
            [
                'key' => 'sudo',
                'label' => 'Root or passwordless sudo',
                'passed' => $failedKey !== 'sudo',
                'blocking' => true,
                'message' => $failedKey === 'sudo' ? $detail : ($failedKey === 'os' ? 'Privilege check passed.' : 'Skipped.'),
            ],
            [
                'key' => 'os',
                'label' => 'Supported operating system',
                'passed' => $failedKey !== 'os',
                'blocking' => true,
                'message' => $failedKey === 'os' ? $detail : 'Skipped until prior checks pass.',
            ],
            [
                'key' => 'cpu',
                'label' => 'Minimum 1 CPU',
                'passed' => false,
                'blocking' => true,
                'message' => 'Skipped until prior checks pass.',
            ],
            [
                'key' => 'memory',
                'label' => 'Minimum 2 GB RAM',
                'passed' => false,
                'blocking' => true,
                'message' => 'Skipped until prior checks pass.',
            ],
            [
                'key' => 'disk',
                'label' => 'Minimum 15 GB disk',
                'passed' => false,
                'blocking' => true,
                'message' => 'Skipped until prior checks pass.',
            ],
            [
                'key' => 'docker',
                'label' => 'Docker availability',
                'passed' => false,
                'blocking' => false,
                'message' => 'Skipped until prior checks pass.',
            ],
        ];

        return $this->decorate([
            'success' => false,
            'message' => $detail,
            'checks' => $checks,
            'system' => [],
        ]);
    }

    /**
     * @return array{success: bool, message: string, driver: string, checks: list<array<string, mixed>>, system: array<string, mixed>}
     */
    private function failure(string $message, ?string $detail = null): array
    {
        $detail ??= $message;

        return $this->decorate([
            'success' => false,
            'message' => $message,
            'checks' => [
                [
                    'key' => 'ssh',
                    'label' => 'Host reachable / SSH access',
                    'passed' => false,
                    'blocking' => true,
                    'message' => $detail,
                ],
                [
                    'key' => 'sudo',
                    'label' => 'Root or passwordless sudo',
                    'passed' => false,
                    'blocking' => true,
                    'message' => 'Skipped until SSH access succeeds.',
                ],
                [
                    'key' => 'os',
                    'label' => 'Supported operating system',
                    'passed' => false,
                    'blocking' => true,
                    'message' => 'Skipped until SSH access succeeds.',
                ],
                [
                    'key' => 'cpu',
                    'label' => 'Minimum 1 CPU',
                    'passed' => false,
                    'blocking' => true,
                    'message' => 'Skipped until SSH access succeeds.',
                ],
                [
                    'key' => 'memory',
                    'label' => 'Minimum 2 GB RAM',
                    'passed' => false,
                    'blocking' => true,
                    'message' => 'Skipped until SSH access succeeds.',
                ],
                [
                    'key' => 'disk',
                    'label' => 'Minimum 15 GB disk',
                    'passed' => false,
                    'blocking' => true,
                    'message' => 'Skipped until SSH access succeeds.',
                ],
                [
                    'key' => 'docker',
                    'label' => 'Docker availability',
                    'passed' => false,
                    'blocking' => false,
                    'message' => 'Skipped until SSH access succeeds.',
                ],
            ],
            'system' => [],
        ]);
    }

    private function safeMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            return 'The server connection could not be verified.';
        }

        return str($message)->limit(300)->toString();
    }
}
