<?php

namespace Tests\Unit;

use App\Services\Infrastructure\SSHServerExecutor;
use App\Services\Servers\ServerConnectionTester;
use PHPUnit\Framework\TestCase;

class SshPrivilegeCheckTest extends TestCase
{
    public function test_root_username_passes_even_when_sudo_n_fails(): void
    {
        $this->assertTrue(SSHServerExecutor::sessionIsPrivileged(
            'root',
            "Welcome to Ubuntu\n0\n",
            "sudo: a terminal is required to read the password\n1\n"
        ));
    }

    public function test_root_username_passes_when_id_output_is_empty(): void
    {
        $this->assertTrue(SSHServerExecutor::sessionIsPrivileged('root', '', ''));
    }

    public function test_non_root_requires_uid_zero_or_passwordless_sudo(): void
    {
        $this->assertFalse(SSHServerExecutor::sessionIsPrivileged('ubuntu', '1000', '1'));
        $this->assertTrue(SSHServerExecutor::sessionIsPrivileged('ubuntu', '0', '1'));
        $this->assertTrue(SSHServerExecutor::sessionIsPrivileged('ubuntu', '1000', "sudo: ok\n0"));
    }

    public function test_os_release_parser_handles_crlf_and_quotes(): void
    {
        $crlf = "PRETTY_NAME=\"Ubuntu 22.04.5 LTS\"\r\nNAME=\"Ubuntu\"\r\nVERSION_ID=\"22.04\"\r\nID=ubuntu\r\n";
        $this->assertSame('ubuntu-22.04', SSHServerExecutor::parseOperatingSystem($crlf));

        $single = "ID='ubuntu'\nVERSION_ID='22.04'\n";
        $this->assertSame('ubuntu-22.04', SSHServerExecutor::parseOperatingSystem($single));

        $this->assertNull(SSHServerExecutor::parseOperatingSystem("Welcome to Ubuntu\n"));
    }

    public function test_memtotal_parser_ignores_motd_and_converts_2gb_droplet_kb(): void
    {
        $motd = "Welcome to Ubuntu 24.04 LTS\n";
        $this->assertSame(0, SSHServerExecutor::parseMemTotalKb($motd));

        $twoGb = "MemTotal:        2031608 kB\nMemFree:          120000 kB\n";
        $this->assertSame(2031608, SSHServerExecutor::parseMemTotalKb($twoGb));
        $this->assertSame(1983, (int) floor(2031608 / 1024));
        $this->assertGreaterThanOrEqual(1900, (int) floor(SSHServerExecutor::parseMemTotalKb($twoGb) / 1024));

        $oneGb = "MemTotal:         997244 kB\n";
        $this->assertLessThan(1900, (int) floor(SSHServerExecutor::parseMemTotalKb($oneGb) / 1024));
        $this->assertSame(2031608, SSHServerExecutor::parseMemTotalKb("Welcome to Ubuntu 24.04\n".$twoGb));
    }

    public function test_expired_password_warning_is_detected(): void
    {
        $this->assertTrue(SSHServerExecutor::looksLikeExpiredPassword(
            "WARNING: Your password has expired.\nPassword change required but no TTY available.\n"
        ));
        $this->assertTrue(SSHServerExecutor::looksLikeExpiredPassword(
            'You must change your password now and login again!'
        ));
        $this->assertFalse(SSHServerExecutor::looksLikeExpiredPassword('Docker version 27.0.3'));
    }

    public function test_memory_rule_accepts_advertised_2gb_and_rejects_1gb(): void
    {
        $this->assertSame(2048, ServerConnectionTester::resolveMemoryMb(2, 2048));
        $this->assertSame(1983, ServerConnectionTester::resolveMemoryMb(1983, 2048));
        $this->assertSame(960, ServerConnectionTester::resolveMemoryMb(960, 1024));
        $this->assertGreaterThanOrEqual(ServerConnectionTester::MIN_MEMORY_MB, 1983);
        $this->assertLessThan(ServerConnectionTester::MIN_MEMORY_MB, 1024);
        $this->assertLessThan(ServerConnectionTester::MIN_MEMORY_MB, 960);
    }
}
