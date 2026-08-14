<?php

namespace Tests\Unit;

use Tests\TestCase;

class ProcessConfigurationTest extends TestCase
{
    public function test_local_development_starts_redis_and_uses_the_platform_queue_launcher(): void
    {
        $composer = json_decode(
            file_get_contents(base_path('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('@php artisan platform:ensure-redis', $composer['scripts']['dev:redis']);

        $dev = implode(' ', $composer['scripts']['dev']);

        $this->assertStringContainsString('@dev:redis', $dev);
        $this->assertStringContainsString('php artisan serve', $dev);
        $this->assertStringContainsString('php artisan platform:queue', $dev);
        $this->assertStringContainsString('php artisan reverb:start', $dev);
        $this->assertStringContainsString('php artisan schedule:work', $dev);
        $this->assertStringNotContainsString('queue:work', $dev);
    }

    public function test_production_supervisor_keeps_all_long_running_services_alive(): void
    {
        $supervisor = file_get_contents(base_path('deploy/supervisor/upentra-services.conf.example'));

        $this->assertStringContainsString('[program:upentra-horizon]', $supervisor);
        $this->assertStringContainsString('[program:upentra-reverb]', $supervisor);
        $this->assertStringContainsString('[program:upentra-scheduler]', $supervisor);
        $this->assertSame(3, substr_count($supervisor, 'autostart=true'));
        $this->assertSame(3, substr_count($supervisor, 'autorestart=true'));
    }
}
