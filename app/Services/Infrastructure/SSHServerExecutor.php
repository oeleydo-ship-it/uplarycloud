<?php

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ServerAuthenticationMethod;
use App\Exceptions\RemoteCommandException;
use App\Models\Server;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

class SSHServerExecutor implements ServerExecutorInterface
{
    public function test(Server $server): array
    {
        try {
            $ssh = $this->connection($server);
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => $this->friendlyError($exception, $server),
                'system' => [],
            ];
        }

        $userId = trim((string) $ssh->exec('id -u'));
        $sudoStatus = trim((string) $ssh->exec('sudo -n true >/dev/null 2>&1; echo $?'));
        if ($userId !== '0' && $sudoStatus !== '0') {
            throw new RuntimeException('The SSH user requires root or passwordless sudo access.');
        }

        $osRelease = (string) $ssh->exec('cat /etc/os-release');
        preg_match('/^ID=(?:"?)([^"\r\n]+)(?:"?)$/m', $osRelease, $id);
        preg_match('/^VERSION_ID=(?:"?)([^"\r\n]+)(?:"?)$/m', $osRelease, $version);
        $os = strtolower(($id[1] ?? '').'-'.($version[1] ?? ''));
        if (! in_array($os, config('infrastructure.supported_operating_systems'), true)) {
            throw new RuntimeException('The server operating system is not supported.');
        }

        $memoryKb = (int) preg_replace('/\D+/', '', (string) $ssh->exec('awk \'/MemTotal/ {print $2}\' /proc/meminfo'));
        $diskKb = (int) trim((string) $ssh->exec('df -Pk / | awk \'NR==2 {print $2}\''));
        $docker = trim((string) $ssh->exec('command -v docker >/dev/null 2>&1 && docker --version || true'));

        return [
            'success' => true,
            'message' => 'SSH authentication and system requirements verified.',
            'system' => [
                'operating_system' => $os,
                'cpu_cores' => max(1, (int) trim((string) $ssh->exec('nproc'))),
                'memory_mb' => (int) floor($memoryKb / 1024),
                'disk_gb' => (int) floor($diskKb / 1024 / 1024),
                'docker_available' => $docker !== '',
                'docker_version' => $docker,
            ],
        ];
    }

    public function execute(Server $server, string $command, ?int $timeoutSeconds = null): string
    {
        if ($command === '' || strlen($command) > 100_000 || str_contains($command, "\0")) {
            throw new RuntimeException('The infrastructure command is invalid.');
        }

        $timeout = max(1, $timeoutSeconds ?? (int) $server->connection_timeout);
        $ssh = $this->connection($server);
        $ssh->setTimeout($timeout);
        $output = (string) $ssh->exec($command);
        $exitCode = $ssh->getExitStatus();

        if ($exitCode === 0) {
            return $output;
        }

        $stderr = (string) $ssh->getStdError();
        $timedOut = $ssh->isTimeout();
        $exception = new RemoteCommandException(
            $command,
            $exitCode,
            $output,
            $stderr,
            $timedOut,
            $timeout,
            'The remote infrastructure command failed.'
        );
        $detail = $exception->detail();

        $message = $timedOut
            ? 'The remote infrastructure command timed out after '.$timeout.'s.'
            : 'The remote infrastructure command failed'.($exitCode === false ? '' : ' (exit '.$exitCode.')').'.';

        if ($detail !== '') {
            $message .= ' '.str($detail)->limit(500);
        }

        throw new RemoteCommandException($command, $exitCode, $output, $stderr, $timedOut, $timeout, $message);
    }

    public function upload(Server $server, string $localPath, string $remotePath): void
    {
        if (! is_file($localPath)) {
            throw new RuntimeException('The local upload file does not exist.');
        }

        $sftp = $this->sftp($server);
        if (! $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException('The remote upload failed.');
        }
    }

    public function download(Server $server, string $remotePath, string $localPath): void
    {
        if (! $this->sftp($server)->get($remotePath, $localPath)) {
            throw new RuntimeException('The remote download failed.');
        }
    }

    private function connection(Server $server): SSH2
    {
        $server->loadMissing('credential');
        $server->credential ?? throw new RuntimeException('Server credentials are missing.');

        try {
            $ssh = new SSH2($server->ip_address, (int) $server->ssh_port, (int) $server->connection_timeout);
        } catch (Throwable $exception) {
            throw new RuntimeException($this->friendlyError($exception, $server), 0, $exception);
        }

        try {
            $authenticated = $ssh->login($server->ssh_username, $this->authenticationValue($server));
        } catch (Throwable $exception) {
            throw new RuntimeException($this->friendlyError($exception, $server), 0, $exception);
        }

        if (! $authenticated) {
            $detail = trim((string) $ssh->getLastError());
            throw new RuntimeException(
                $detail !== ''
                    ? 'SSH authentication failed: '.$detail
                    : 'SSH authentication failed. Check the username, private key or password, and passphrase.'
            );
        }

        $ssh->setTimeout((int) $server->connection_timeout);

        return $ssh;
    }

    private function sftp(Server $server): SFTP
    {
        $server->loadMissing('credential');
        $server->credential ?? throw new RuntimeException('Server credentials are missing.');

        try {
            $sftp = new SFTP($server->ip_address, (int) $server->ssh_port, (int) $server->connection_timeout);
            if (! $sftp->login($server->ssh_username, $this->authenticationValue($server))) {
                throw new RuntimeException('SFTP authentication failed.');
            }
        } catch (Throwable $exception) {
            throw new RuntimeException($this->friendlyError($exception, $server), 0, $exception);
        }

        return $sftp;
    }

    private function authenticationValue(Server $server): string|PrivateKey
    {
        $credential = $server->credential;

        if ($server->authentication_method === ServerAuthenticationMethod::Password) {
            return $credential->password ?? throw new RuntimeException('The server password is missing.');
        }

        if (! $credential->private_key) {
            throw new RuntimeException('The server private key is missing.');
        }

        try {
            $key = PublicKeyLoader::loadPrivateKey($credential->private_key, $credential->passphrase ?: false);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Could not load the private key. Check the key format and passphrase.',
                0,
                $exception
            );
        }

        if (! $key instanceof PrivateKey) {
            throw new RuntimeException('The private key type is not supported.');
        }

        return $key;
    }

    private function friendlyError(Throwable $exception, Server $server): string
    {
        $message = trim($exception->getMessage());
        $lower = strtolower($message);
        $host = $server->ip_address.':'.$server->ssh_port;

        if (
            str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'operation timed out')
        ) {
            return "Connection timed out while reaching {$host}. Check the IP, SSH port, and firewall rules.";
        }

        if (
            str_contains($lower, 'connection refused')
            || str_contains($lower, 'actively refused')
            || str_contains($lower, 'unable to connect')
            || str_contains($lower, 'connection reset')
            || str_contains($lower, 'network is unreachable')
            || str_contains($lower, 'no route to host')
        ) {
            return "Could not reach {$host} (connection refused or unreachable). Confirm SSH is listening and the host is online.";
        }

        if (
            str_contains($lower, 'authentication failed')
            || str_contains($lower, 'login incorrect')
            || str_contains($lower, 'permission denied')
        ) {
            return $message !== ''
                ? $message
                : 'SSH authentication failed. Check the username, private key or password, and passphrase.';
        }

        if (
            str_contains($lower, 'private key')
            || str_contains($lower, 'passphrase')
            || str_contains($lower, 'unable to load key')
            || str_contains($lower, 'decrypt')
        ) {
            return 'Could not load the private key. Check the key format and passphrase.';
        }

        if ($message === '') {
            return "The SSH connection to {$host} could not be established.";
        }

        return str($message)->limit(300)->toString();
    }
}
