<?php

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ServerAuthenticationMethod;
use App\Exceptions\RemoteCommandException;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\TimeoutException;
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

        $ssh->exec('true');

        if (strcasecmp((string) $server->ssh_username, 'root') !== 0) {
            $userId = (string) $ssh->exec('id -u');
            $sudoStatus = (string) $ssh->exec('sudo -n true >/dev/null 2>&1; echo $?');
            if (! self::sessionIsPrivileged((string) $server->ssh_username, $userId, $sudoStatus)) {
                throw new RuntimeException('The SSH user requires root or passwordless sudo access.');
            }
        }

        $osRelease = (string) $ssh->exec('cat /etc/os-release 2>/dev/null');
        $os = self::parseOperatingSystem($osRelease);
        $supported = config('infrastructure.supported_operating_systems');
        if ($os === null || ! in_array($os, $supported, true)) {
            $recorded = strtolower((string) ($server->provider_image ?: $server->operating_system));
            if (in_array($recorded, $supported, true)) {
                $os = $recorded;
            } elseif ($os === null) {
                throw new RuntimeException('The SSH session is not ready to read the operating system.');
            } else {
                throw new RuntimeException('The server operating system is not supported.');
            }
        }

        $meminfo = (string) $ssh->exec('cat /proc/meminfo');
        $memoryKb = self::parseMemTotalKb($meminfo);
        if ($memoryKb === 0) {
            $memoryKb = self::parseMemTotalKb((string) $ssh->exec('awk \'/MemTotal/ {print $2}\' /proc/meminfo'));
        }
        $diskOut = (string) $ssh->exec('df -Pk / | awk \'NR==2 {print $2}\'');
        $diskKb = preg_match('/^\s*(\d+)\s*$/m', str_replace("\r", '', $diskOut), $match) ? (int) $match[1] : 0;
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

        return $this->runRemoteCommand($server, $command, $timeout, allowExpiryRecovery: true);
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

    private function runRemoteCommand(Server $server, string $command, int $timeout, bool $allowExpiryRecovery): string
    {
        $ssh = $this->connection($server);
        $ssh->setTimeout($timeout);
        $output = (string) $ssh->exec($command);
        $exitCode = $ssh->getExitStatus();
        $stderr = (string) $ssh->getStdError();
        $combined = $output."\n".$stderr;
        $timedOut = $ssh->isTimeout();

        if ($allowExpiryRecovery && self::looksLikeExpiredPassword($combined)) {
            $ssh->disconnect();
            $this->clearExpiredRootPassword($server);

            return $this->runRemoteCommand($server, $command, $timeout, allowExpiryRecovery: false);
        }

        if ($exitCode === 0) {
            return $output;
        }

        $message = $timedOut
            ? 'The remote infrastructure command timed out after '.$timeout.'s.'
            : 'The remote infrastructure command failed'.($exitCode === false ? '' : ' (exit '.$exitCode.')').'.';

        $detail = trim($stderr) !== '' ? trim($stderr) : trim($output);
        $detail = trim(preg_replace('/\s*\R\s*/', ' | ', $detail) ?? $detail);
        if ($detail !== '') {
            $message .= ' '.str($detail)->limit(500);
        }

        throw new RemoteCommandException($command, $exitCode, $output, $stderr, $timedOut, $timeout, $message);
    }

    private function connection(Server $server): SSH2
    {
        $ssh = $this->authenticate($server);

        if (strcasecmp((string) $server->ssh_username, 'root') !== 0) {
            return $ssh;
        }

        $probe = (string) $ssh->exec('true');
        $stderr = (string) $ssh->getStdError();
        $exit = $ssh->getExitStatus();
        if (
            ! $ssh->isTimeout()
            && $exit === 0
            && ! self::looksLikeExpiredPassword($probe."\n".$stderr)
        ) {
            return $ssh;
        }

        if (! $ssh->isTimeout() && ($exit !== 0 || self::looksLikeExpiredPassword($probe."\n".$stderr))) {
            $ssh->disconnect();
            $this->clearExpiredRootPassword($server);

            return $this->authenticate($server);
        }

        return $ssh;
    }

    private function authenticate(Server $server): SSH2
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

    /**
     * Ubuntu 24.04 cloud images often expire the root password. PAM then
     * rejects non-interactive exec with "no TTY available". Request a real PTY,
     * wait until chage/passwd actually finishes, then use a fresh connection.
     */
    private function clearExpiredRootPassword(Server $server): void
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $buffer = $this->runExpiryClearSession($server);
            if (str_contains($buffer, 'UPLARY_CHAGE_DONE')) {
                Log::info('Cleared expired root password on TTY', [
                    'server_id' => $server->id,
                    'ip' => $server->ip_address,
                    'attempt' => $attempt + 1,
                ]);
                break;
            }
        }

        $verify = $this->authenticate($server);
        $probe = (string) $verify->exec('true');
        $stderr = (string) $verify->getStdError();
        $verify->disconnect();

        if (self::looksLikeExpiredPassword($probe."\n".$stderr)) {
            throw new RuntimeException(
                'Root password expiry could not be cleared on a TTY session. '.str($probe.' '.$stderr)->limit(300)
            );
        }
    }

    private function runExpiryClearSession(Server $server): string
    {
        $ssh = $this->authenticate($server);
        $ssh->setTerminal('xterm');
        $ssh->setWindowSize(80, 24);
        $ssh->enablePTY();
        $ssh->setTimeout(8);

        $clear = 'chage -d -1 root; chage -I -1 -m 0 -M 99999 root; passwd -x -1 root >/dev/null 2>&1; echo UPLARY_CHAGE_DONE';
        if ($ssh->exec($clear) === false) {
            $ssh->disablePTY();
            $ssh->disconnect();
            throw new RuntimeException('Could not open a TTY session to clear the expired root password.');
        }

        $buffer = '';
        $sentCurrent = false;
        $newPasswordWrites = 0;
        $deadline = microtime(true) + 40;

        try {
            while (microtime(true) < $deadline) {
                $ssh->setTimeout(5);
                try {
                    $chunk = $ssh->read('', SSH2::READ_NEXT);
                } catch (TimeoutException) {
                    $chunk = '';
                }

                if ($chunk === true) {
                    break;
                }
                if (is_string($chunk) && $chunk !== '') {
                    $buffer .= $chunk;
                }

                if (str_contains($buffer, 'UPLARY_CHAGE_DONE')) {
                    break;
                }

                $tail = strtolower(substr($buffer, -400));
                if (! $sentCurrent && str_contains($tail, 'current password:')) {
                    $ssh->write($this->rootPasswordForPrompt($server)."\n");
                    $sentCurrent = true;
                    continue;
                }
                if ($newPasswordWrites < 2 && preg_match('/(new password:|retype|re-enter)/i', $tail)) {
                    $ssh->write($this->rootPasswordForPrompt($server)."\n");
                    $newPasswordWrites++;
                }
            }
        } finally {
            try {
                $ssh->disablePTY();
                $ssh->disconnect();
            } catch (Throwable) {
            }
        }

        return $buffer;
    }

    public static function looksLikeExpiredPassword(string $output): bool
    {
        $lower = strtolower($output);

        return str_contains($lower, 'password has expired')
            || str_contains($lower, 'password change required')
            || str_contains($lower, 'you must change your password')
            || (str_contains($lower, 'no tty available') && str_contains($lower, 'password'));
    }

    private function rootPasswordForPrompt(Server $server): string
    {
        $server->loadMissing('credential');

        return (string) ($server->credential?->password ?? '');
    }

    /**
     * Cloud-init banners and `sudo -n` on Ubuntu often wrap `id -u` / `echo $?`
     * in extra output. Connecting as root is sufficient; passwordless sudo is
     * only required for non-root users.
     */
    public static function sessionIsPrivileged(string $username, string $idOutput, string $sudoOutput): bool
    {
        if (strcasecmp(trim($username), 'root') === 0) {
            $uid = self::lastInteger($idOutput);

            return $uid === null || $uid === 0;
        }

        if (self::lastInteger($idOutput) === 0) {
            return true;
        }

        return self::lastInteger($sudoOutput) === 0;
    }

    public static function parseOperatingSystem(string $osRelease): ?string
    {
        $osRelease = str_replace("\r", '', $osRelease);
        if (! preg_match('/^ID=(["\']?)([^"\'\n]+)\1/m', $osRelease, $id)) {
            return null;
        }
        if (! preg_match('/^VERSION_ID=(["\']?)([^"\'\n]+)\1/m', $osRelease, $version)) {
            return null;
        }

        $os = strtolower(trim($id[2]).'-'.trim($version[2]));
        foreach (['ubuntu-24.04', 'ubuntu-22.04', 'debian-12'] as $supported) {
            if ($os === $supported || str_starts_with($os, $supported.'.')) {
                return $supported;
            }
        }

        return $os;
    }

    public static function parseMemTotalKb(string $output): int
    {
        $output = str_replace("\r", '', $output);
        if (preg_match('/MemTotal:\s+(\d+)/i', $output, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/^\s*(\d+)\s*$/m', $output, $match)) {
            return (int) $match[1];
        }

        return 0;
    }

    private static function lastInteger(string $output): ?int
    {
        if (preg_match_all('/^\s*(\d+)\s*$/m', str_replace("\r", '', $output), $matches) && $matches[1] !== []) {
            return (int) $matches[1][array_key_last($matches[1])];
        }

        if (preg_match('/\buid=(\d+)\b/', $output, $match)) {
            return (int) $match[1];
        }

        return null;
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
