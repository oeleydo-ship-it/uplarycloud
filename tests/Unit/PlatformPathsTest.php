<?php

namespace Tests\Unit;

use App\Support\PlatformPaths;
use Tests\TestCase;

class PlatformPathsTest extends TestCase
{
    public function test_default_remote_paths_use_uplary_root(): void
    {
        config()->set('infrastructure.remote_root', '/opt/uplary');

        $this->assertSame('/opt/uplary', PlatformPaths::root());
        $this->assertSame('/opt/uplary/builds/example', PlatformPaths::builds().'/example');
        $this->assertSame('/opt/uplary/keys/deploy-key', PlatformPaths::keys().'/deploy-key');
    }

    public function test_non_root_install_command_creates_tree_and_chowns_workspace(): void
    {
        config()->set('infrastructure.remote_root', '/opt/uplary');

        $command = PlatformPaths::installTreeCommand('uplary');

        $this->assertStringContainsString("install -d -m 0755 '/opt/uplary'", $command);
        $this->assertStringContainsString("'/opt/uplary/builds'", $command);
        $this->assertStringContainsString("chown -R 'uplary:uplary' '/opt/uplary'", $command);
    }

    public function test_deployment_prepare_command_uses_sudo_for_non_root_users(): void
    {
        config()->set('infrastructure.remote_root', '/opt/uplary');

        $command = PlatformPaths::ensureTreeCommand('uplary');

        $this->assertStringStartsWith("sudo -n sh -c 'set -e;", $command);
        $this->assertStringContainsString("'/opt/uplary/builds'", $command);
        $this->assertStringContainsString('chown -R', $command);
        $this->assertStringContainsString('uplary:uplary', $command);
    }
}
