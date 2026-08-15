<?php

namespace Tests\Unit;

use App\Services\Servers\ServerMaintenanceService;
use Tests\TestCase;

class ServerMaintenanceServiceTest extends TestCase
{
    public function test_maintenance_route_points_at_maintenance_container(): void
    {
        $service = app(ServerMaintenanceService::class);
        $method = new \ReflectionMethod(ServerMaintenanceService::class, 'maintenanceRouteConfiguration');
        $method->setAccessible(true);
        $yaml = $method->invoke($service, 'uplary-maintenance');

        $this->assertStringContainsString('priority: 10000', $yaml);
        $this->assertStringContainsString('http://uplary-maintenance:80', $yaml);
        $this->assertStringContainsString('HostRegexp', $yaml);
    }
}
