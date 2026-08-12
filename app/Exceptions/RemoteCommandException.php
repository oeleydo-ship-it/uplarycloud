<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a command executed on a remote host exits non-zero or times out.
 *
 * Carries the raw stdout/stderr so deployment stages can surface the real
 * Docker error ("port is already allocated", "no such image", …) instead of a
 * generic failure message.
 */
class RemoteCommandException extends RuntimeException
{
    public function __construct(
        public readonly string $command,
        public readonly int|false $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly int $timeoutSeconds,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The most useful diagnostic text the host returned, stderr first.
     */
    public function detail(): string
    {
        $detail = trim($this->stderr) !== '' ? trim($this->stderr) : trim($this->stdout);

        return trim(preg_replace('/\s*\R\s*/', ' | ', $detail) ?? $detail);
    }

    /**
     * The command with anything that looks like a secret removed, safe for logs.
     */
    public function redactedCommand(): string
    {
        return self::redact($this->command);
    }

    public static function redact(string $command): string
    {
        return (string) preg_replace(
            ['/(--env|-e)\s+(\'[^\']*\'|"[^"]*"|\S+)/', '/(--password|--token)[= ]\S+/'],
            ['$1 ***', '$1 ***'],
            $command
        );
    }
}
