<?php

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerExecutorInterface;
use App\Enums\ServerAuthenticationMethod;
use App\Exceptions\RemoteCommandException;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\TimeoutException;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class SSHServerExecutor implements ServerExecutorInterface
{
    private ?string $pamPassword = null;
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

    public function ensureReady(Server $server): void
    {
        $maxWait = max(30, (int) config('infrastructure.ssh.ready_timeout', 120));
        $deadline = microtime(true) + $maxWait;
        $attempt = 0;
        $last = null;

        while (microtime(true) < $deadline) {
            $attempt++;

            try {
                $ssh = $this->openAuthenticatedSession($server);
                $ssh->setTimeout(min(30, $this->connectTimeout($server)));
                $ssh->exec('true');
                $ssh->disconnect();

                return;
            } catch (Throwable $exception) {
                $last = $exception;

                if (self::isAuthenticationFailure($exception)) {
                    throw new RuntimeException($this->friendlyError($exception, $server), 0, $exception);
                }

                if (! self::isTransientConnectionError($exception)) {
                    throw new RuntimeException($this->friendlyError($exception, $server), 0, $exception);
                }

                Log::warning('SSH is not ready yet, waiting before retry', [
                    'server_id' => $server->id,
                    'ip' => $server->ip_address,
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);

                sleep(min((int) config('infrastructure.ssh.retry_delay_seconds', 5) * $attempt, 20));
            }
        }

        $host = $server->ip_address.':'.$server->ssh_port;
        throw new RuntimeException(
            "Could not establish SSH to {$host} within {$maxWait}s. "
            .'The server may be overloaded, finishing another deployment, or SSH may be temporarily unavailable. '
            .($last ? str($this->friendlyError($last, $server))->limit(180)->toString() : '')
        );
    }

    public function execute(Server $server, string $command, ?int $timeoutSeconds = null): string
    {
        if ($command === '' || strlen($command) > 100_000 || str_contains($command, "\0")) {
            throw new RuntimeException('The infrastructure command is invalid.');
        }

        $timeout = max(1, $timeoutSeconds ?? (int) config('infrastructure.command_timeouts.default', 180));

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
        $attempts = max(1, (int) config('infrastructure.ssh.connect_attempts', 5));
        $last = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                sleep(min((int) config('infrastructure.ssh.retry_delay_seconds', 5) * $attempt, 20));
            }

            try {
                return $this->openAuthenticatedSession($server);
            } catch (RuntimeException $exception) {
                if (self::isAuthenticationFailure($exception) || ! self::isTransientConnectionError($exception)) {
                    throw $exception;
                }

                $last = $exception;
            } catch (Throwable $exception) {
                $last = $exception;
            }

            if ($attempt < $attempts - 1 && $last !== null && self::isTransientConnectionError($last)) {
                Log::warning('SSH connection attempt failed, retrying', [
                    'server_id' => $server->id,
                    'ip' => $server->ip_address,
                    'attempt' => $attempt + 1,
                    'message' => $last->getMessage(),
                ]);

                continue;
            }

            if ($last !== null) {
                throw new RuntimeException($this->friendlyError($last, $server), 0, $last);
            }
        }

        throw new RuntimeException($this->friendlyError($last ?? new RuntimeException('SSH connection failed.'), $server), 0, $last);
    }

    private function openAuthenticatedSession(Server $server): SSH2
    {
        $server->loadMissing('credential');
        $server->credential ?? throw new RuntimeException('Server credentials are missing.');

        $connectTimeout = $this->connectTimeout($server);
        $ssh = new SSH2($server->ip_address, (int) $server->ssh_port, $connectTimeout);

        $authenticated = $ssh->login($server->ssh_username, $this->authenticationValue($server));
        if (! $authenticated) {
            $detail = trim((string) $ssh->getLastError());
            throw new RuntimeException(
                $detail !== ''
                    ? 'SSH authentication failed: '.$detail
                    : 'SSH authentication failed. Check the username, private key or password, and passphrase.'
            );
        }

        $ssh->setTimeout(min(30, $connectTimeout));

        return $ssh;
    }

    private function connectTimeout(Server $server): int
    {
        return max(
            (int) $server->connection_timeout,
            (int) config('infrastructure.ssh.connect_timeout', 60)
        );
    }

    public static function isAuthenticationFailure(Throwable $exception): bool
    {
        $lower = strtolower(trim($exception->getMessage()));

        return str_contains($lower, 'authentication failed')
            || str_contains($lower, 'login incorrect')
            || str_contains($lower, 'permission denied (publickey')
            || str_contains($lower, 'private key is missing')
            || str_contains($lower, 'password is missing')
            || str_contains($lower, 'could not load the private key');
    }

    public static function isTransientConnectionError(Throwable $exception): bool
    {
        $lower = strtolower(trim($exception->getMessage()));

        return str_contains($lower, 'error reading ssh identification string')
            || str_contains($lower, 'connection reset')
            || str_contains($lower, 'broken pipe')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'operation timed out')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'unable to connect')
            || str_contains($lower, 'no route to host')
            || str_contains($lower, 'network is unreachable')
            || $exception instanceof TimeoutException;
    }

    /**
     * Ubuntu 24.04 cloud images often expire the root password. PAM then
     * rejects non-interactive exec with "no TTY available". Open an interactive
     * login shell (real PTY), wait until chage/passwd finishes, then verify on
     * a fresh non-PTY connection. Fall back to system `ssh -tt` if needed.
     */
    private function clearExpiredRootPassword(Server $server): void
    {
        $buffer = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $buffer = $this->runExpiryClearSession($server);
            if (str_contains($buffer, 'UPLARY_CHAGE_DONE')) {
                Log::info('Cleared expired root password on interactive TTY', [
                    'server_id' => $server->id,
                    'ip' => $server->ip_address,
                    'attempt' => $attempt + 1,
                ]);
                break;
            }
        }

        if (! $this->rootPasswordIsUsable($server) && PHP_OS_FAMILY !== 'Windows') {
            $sshTt = $this->clearExpiredRootPasswordViaSystemSsh($server);
            $buffer .= "\n".$sshTt;
            if (str_contains($sshTt, 'UPLARY_CHAGE_DONE')) {
                Log::info('Cleared expired root password via ssh -tt', [
                    'server_id' => $server->id,
                    'ip' => $server->ip_address,
                ]);
            }
        }

        if (! $this->rootPasswordIsUsable($server)) {
            throw new RuntimeException(
                'Root password expiry could not be cleared on a TTY session. '.str($buffer)->limit(300)
            );
        }
    }

    private function rootPasswordIsUsable(Server $server): bool
    {
        $verify = $this->authenticate($server);
        $probe = (string) $verify->exec('true');
        $stderr = (string) $verify->getStdError();
        $exit = $verify->getExitStatus();
        $verify->disconnect();

        return $exit === 0 && ! self::looksLikeExpiredPassword($probe."\n".$stderr);
    }

    private function expiryClearCommand(): string
    {
        return 'chage -d -1 root; chage -I -1 -m 0 -M 99999 root; passwd -x -1 root >/dev/null 2>&1; echo UPLARY_CHAGE_DONE';
    }

    private function runExpiryClearSession(Server $server): string
    {
        $ssh = $this->authenticate($server);
        $ssh->setTerminal('xterm');
        $ssh->setWindowSize(80, 24);
        $ssh->setTimeout(8);
        $ssh->openShell();

        $buffer = '';
        $sentCurrent = false;
        $newPasswordWrites = 0;
        $sentChage = false;
        $secret = $this->passwordToSatisfyPam($server);
        $deadline = microtime(true) + 50;

        try {
            while (microtime(true) < $deadline) {
                $ssh->setTimeout(4);
                try {
                    $chunk = $ssh->read('', SSH2::READ_NEXT, SSH2::CHANNEL_SHELL);
                } catch (Throwable) {
                    $chunk = '';
                }

                if ($chunk === true) {
                    $chunk = '';
                }
                if (is_string($chunk) && $chunk !== '') {
                    $buffer .= $chunk;
                }

                if (str_contains($buffer, 'UPLARY_CHAGE_DONE')) {
                    break;
                }

                $plain = strtolower(preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $buffer) ?? $buffer);
                $tail = substr($plain, -600);
                $forcedChange = str_contains($plain, 'change your password')
                    || str_contains($plain, 'changing password')
                    || str_contains($plain, 'password immediately')
                    || str_contains($plain, 'required to change');

                try {
                    if (! $sentCurrent && preg_match('/(current password:|\(current\) unix password:)/i', $tail)) {
                        $current = $this->rootPasswordForPrompt($server);
                        $ssh->write(($current !== '' ? $current : $secret)."\n", SSH2::CHANNEL_SHELL);
                        $sentCurrent = true;
                        continue;
                    }
                    if ($newPasswordWrites < 2 && preg_match('/((enter )?new( unix)? password:|retype|re-enter|reenter)/i', $tail)) {
                        $ssh->write($secret."\n", SSH2::CHANNEL_SHELL);
                        $newPasswordWrites++;
                        continue;
                    }
                    if ($forcedChange && $newPasswordWrites < 2) {
                        $ssh->write($secret."\n", SSH2::CHANNEL_SHELL);
                        $newPasswordWrites++;
                        continue;
                    }

                    if (! $sentChage && self::looksLikeShellPrompt($buffer)) {
                        $ssh->write($this->expiryClearCommand()."\n", SSH2::CHANNEL_SHELL);
                        $sentChage = true;
                    }
                } catch (Throwable) {
                    break;
                }
            }

            if (! $sentChage && ! str_contains($buffer, 'UPLARY_CHAGE_DONE') && self::looksLikeShellPrompt($buffer)) {
                $ssh->write($this->expiryClearCommand()."\n", SSH2::CHANNEL_SHELL);
                $ssh->setTimeout(12);
                try {
                    $buffer .= (string) $ssh->read('UPLARY_CHAGE_DONE', SSH2::READ_SIMPLE, SSH2::CHANNEL_SHELL);
                } catch (TimeoutException) {
                }
            }
        } finally {
            try {
                $ssh->disconnect();
            } catch (Throwable) {
            }
        }

        Log::info('Root expiry interactive TTY output', [
            'server_id' => $server->id,
            'output' => str(str_replace($secret, '***', $buffer))->limit(1500)->toString(),
            'sent_chage' => $sentChage,
            'password_prompts' => $newPasswordWrites,
        ]);

        return $buffer;
    }

    private function passwordToSatisfyPam(Server $server): string
    {
        if ($this->pamPassword !== null) {
            return $this->pamPassword;
        }

        $existing = $this->rootPasswordForPrompt($server);
        $this->pamPassword = $existing !== '' ? $existing : Str::password(24, symbols: false);

        return $this->pamPassword;
    }

    private function clearExpiredRootPasswordViaSystemSsh(Server $server): string
    {
        $server->loadMissing('credential');
        $binary = (new ExecutableFinder)->find('ssh');
        $key = $server->credential?->private_key;
        if ($binary === null || $key === null || $key === '') {
            return '';
        }

        $keyFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'uplary-ssh-'.bin2hex(random_bytes(6)).'.pem';
        file_put_contents($keyFile, str_ends_with($key, "\n") ? $key : $key."\n");
        $this->restrictPrivateKeyFile($keyFile);

        $knownHosts = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $cmd = [
            $binary,
            '-tt',
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile='.$knownHosts,
            '-o', 'IdentitiesOnly=yes',
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=20',
            '-p', (string) $server->ssh_port,
            '-i', $keyFile,
            $server->ssh_username.'@'.$server->ip_address,
        ];

        $secret = $this->passwordToSatisfyPam($server);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            @unlink($keyFile);

            return '';
        }

        foreach ([0, 1, 2] as $fd) {
            stream_set_blocking($pipes[$fd], false);
        }

        $buffer = '';
        $sentCurrent = false;
        $newPasswordWrites = 0;
        $sentChage = false;
        $deadline = microtime(true) + 50;

        try {
            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                foreach ([1, 2] as $fd) {
                    $buffer .= (string) stream_get_contents($pipes[$fd]);
                }
                if (str_contains($buffer, 'UPLARY_CHAGE_DONE')) {
                    break;
                }

                $plain = strtolower(preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $buffer) ?? $buffer);
                $forcedChange = str_contains($plain, 'change your password')
                    || str_contains($plain, 'changing password')
                    || str_contains($plain, 'password immediately')
                    || str_contains($plain, 'required to change');

                if (! $sentCurrent && preg_match('/(current password:|\(current\) unix password:)/i', $plain)) {
                    $current = $this->rootPasswordForPrompt($server);
                    fwrite($pipes[0], ($current !== '' ? $current : $secret)."\n");
                    $sentCurrent = true;
                } elseif ($newPasswordWrites < 2 && preg_match('/((enter )?new( unix)? password:|retype|re-enter|reenter)/i', $plain)) {
                    fwrite($pipes[0], $secret."\n");
                    $newPasswordWrites++;
                } elseif ($forcedChange && $newPasswordWrites < 2) {
                    fwrite($pipes[0], $secret."\n");
                    $newPasswordWrites++;
                    usleep(400000);
                } elseif (! $sentChage && self::looksLikeShellPrompt($buffer)) {
                    fwrite($pipes[0], $this->expiryClearCommand()."\n");
                    $sentChage = true;
                }

                if (! ($status['running'] ?? false)) {
                    break;
                }
                usleep(200000);
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);
            @unlink($keyFile);
        }

        Log::info('ssh -tt expiry session', [
            'server_id' => $server->id,
            'output' => str($buffer)->limit(500)->toString(),
            'password_prompts' => $newPasswordWrites,
            'sent_chage' => $sentChage,
        ]);

        return $buffer;
    }

    private function restrictPrivateKeyFile(string $keyFile): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $user = (string) getenv('USERNAME');
            @exec('icacls '.escapeshellarg($keyFile).' /inheritance:r /grant:r '.escapeshellarg($user.':(R)').' 2>NUL');

            return;
        }

        @chmod($keyFile, 0600);
    }

    public static function looksLikeExpiredPassword(string $output): bool
    {
        $lower = strtolower($output);

        return str_contains($lower, 'password has expired')
            || str_contains($lower, 'password change required')
            || str_contains($lower, 'you must change your password')
            || str_contains($lower, 'required to change your password')
            || (str_contains($lower, 'change your password') && str_contains($lower, 'immediately'))
            || (str_contains($lower, 'no tty available') && str_contains($lower, 'password'));
    }

    public static function looksLikeShellPrompt(string $output): bool
    {
        $plain = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', str_replace("\r", '', $output)) ?? $output;
        $tail = substr($plain, -120);

        return (bool) preg_match('/(^|\n)[^\n]*[#$]\s*$/', $tail);
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
            $connectTimeout = $this->connectTimeout($server);
            $sftp = new SFTP($server->ip_address, (int) $server->ssh_port, $connectTimeout);
            if (! $sftp->login($server->ssh_username, $this->authenticationValue($server))) {
                throw new RuntimeException('SFTP authentication failed.');
            }
        } catch (Throwable $exception) {
            if (self::isTransientConnectionError($exception)) {
                sleep((int) config('infrastructure.ssh.retry_delay_seconds', 5));
                $connectTimeout = $this->connectTimeout($server);
                $sftp = new SFTP($server->ip_address, (int) $server->ssh_port, $connectTimeout);
                if (! $sftp->login($server->ssh_username, $this->authenticationValue($server))) {
                    throw new RuntimeException('SFTP authentication failed.');
                }

                return $sftp;
            }

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
            return "Connection timed out while reaching {$host}. The server may be busy finishing another deployment or low on memory; wait a minute and retry.";
        }

        if (str_contains($lower, 'error reading ssh identification string')) {
            return "SSH handshake failed on {$host}. The server may be restarting, overloaded, or SSH may be temporarily unavailable. Wait a minute and retry.";
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
