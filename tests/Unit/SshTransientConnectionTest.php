<?php

namespace Tests\Unit;

use App\Services\Infrastructure\SSHServerExecutor;
use phpseclib3\Exception\TimeoutException;
use RuntimeException;
use Tests\TestCase;

class SshTransientConnectionTest extends TestCase
{
    public function test_identification_string_errors_are_transient(): void
    {
        $this->assertTrue(SSHServerExecutor::isTransientConnectionError(
            new RuntimeException('Error reading SSH identification string; are you sure you\'re connecting to an SSH server?')
        ));
    }

    public function test_authentication_errors_are_not_transient(): void
    {
        $this->assertFalse(SSHServerExecutor::isTransientConnectionError(
            new RuntimeException('SSH authentication failed: Permission denied (publickey).')
        ));
    }

    public function test_timeout_exceptions_are_transient(): void
    {
        $this->assertTrue(SSHServerExecutor::isTransientConnectionError(new TimeoutException('Timed out')));
    }
}
