<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PlatformServiceControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('platform_services.enabled', true);
        config()->set('platform_services.supervisorctl', '/usr/bin/supervisorctl');
        config()->set('platform_services.use_sudo', false);
        config()->set('platform_services.sudo_fallback', true);
        config()->set('platform_services.sudo', '/usr/bin/sudo');
        config()->set('platform_services.services.horizon.program', 'upentra-horizon');
        config()->set('platform_services.services.reverb.program', 'upentra-reverb');
    }

    public function test_superadmin_can_view_platform_service_statuses(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', $process->command);

            return str_contains($command, 'upentra-horizon')
                ? Process::result('upentra-horizon RUNNING pid 123, uptime 0:10:00')
                : Process::result('upentra-reverb STOPPED Not started');
        });

        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->get(route('admin.services'))
            ->assertOk()
            ->assertSee('Platform Services')
            ->assertSee('Laravel Horizon')
            ->assertSee('Running')
            ->assertSee('Laravel Reverb')
            ->assertSee('Stopped');

        Process::assertRanTimes(fn (PendingProcess $process) => in_array('status', $process->command, true), 2);
    }

    public function test_superadmin_can_start_stop_and_restart_allowlisted_services(): void
    {
        Process::fake();
        $admin = User::factory()->create(['is_super_admin' => true]);

        foreach ([
            ['horizon', 'stop'],
            ['horizon', 'start'],
            ['reverb', 'restart'],
        ] as [$service, $action]) {
            $this->actingAs($admin)->post(route('admin.services.control', [$service, $action]))
                ->assertRedirect()
                ->assertSessionHasNoErrors()
                ->assertSessionHas('success');
        }

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['/usr/bin/supervisorctl', 'stop', 'upentra-horizon']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['/usr/bin/supervisorctl', 'start', 'upentra-horizon']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['/usr/bin/supervisorctl', 'restart', 'upentra-reverb']);
    }

    public function test_platform_service_controls_reject_unauthorized_or_unknown_targets(): void
    {
        Process::fake();
        $user = User::factory()->create(['is_super_admin' => false]);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($user)->get(route('admin.services'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.services.control', ['horizon', 'restart']))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.services.control', ['database', 'restart']))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.services.control', ['horizon', 'delete']))->assertNotFound();

        Process::assertNothingRan();
    }

    public function test_superadmin_sees_a_clear_error_when_supervisor_rejects_an_action(): void
    {
        Process::fake([ '*' => Process::result(errorOutput: 'ERROR (no such process)', exitCode: 1) ]);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->post(route('admin.services.control', ['horizon', 'restart']))
            ->assertRedirect()
            ->assertSessionHasErrors('service');
    }

    public function test_supervisor_commands_can_use_non_interactive_sudo(): void
    {
        Process::fake();
        config()->set('platform_services.use_sudo', true);
        config()->set('platform_services.sudo', '/usr/bin/sudo');
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->post(route('admin.services.control', ['reverb', 'start']))
            ->assertSessionHasNoErrors();

        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            '/usr/bin/sudo', '-n', '/usr/bin/supervisorctl', 'start', 'upentra-reverb',
        ]);
    }

    public function test_permission_denied_retries_through_restricted_non_interactive_sudo(): void
    {
        Process::fake(function (PendingProcess $process) {
            return $process->command[0] === '/usr/bin/sudo'
                ? Process::result('upentra-horizon RUNNING pid 123')
                : Process::result(errorOutput: 'Permission denied: supervisor/xmlrpc.py', exitCode: 1);
        });
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->get(route('admin.services'))
            ->assertOk()
            ->assertSee('Running');

        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            '/usr/bin/sudo', '-n', '/usr/bin/supervisorctl', 'status', 'upentra-horizon',
        ]);
    }

    public function test_permission_error_is_replaced_with_actionable_message(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'sudo: a password is required', exitCode: 1)]);
        config()->set('platform_services.use_sudo', true);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->post(route('admin.services.control', ['horizon', 'restart']))
            ->assertSessionHasErrors([
                'service' => 'The PHP user cannot control Supervisor. Install the restricted sudoers rule and enable non-interactive supervisorctl access.',
            ]);
    }
}
