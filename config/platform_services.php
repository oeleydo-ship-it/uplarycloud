<?php

return [
    /* Platform services are controlled through Supervisor using fixed program names. */
    'enabled' => env('PLATFORM_SERVICE_CONTROL_ENABLED', true),
    'supervisorctl' => env('PLATFORM_SUPERVISORCTL_PATH', 'supervisorctl'),
    'use_sudo' => env('PLATFORM_SUPERVISORCTL_USE_SUDO', false),
    'sudo' => env('PLATFORM_SUDO_PATH', 'sudo'),
    'timeout' => (int) env('PLATFORM_SERVICE_CONTROL_TIMEOUT', 10),

    'services' => [
        'horizon' => [
            'name' => 'Laravel Horizon',
            'description' => 'Processes deployments, infrastructure provisioning, backups, and other queued work.',
            'program' => env('PLATFORM_HORIZON_PROGRAM', 'upentra-horizon'),
            'icon' => 'gauge',
            'dashboard_route' => 'horizon.index',
        ],
        'reverb' => [
            'name' => 'Laravel Reverb',
            'description' => 'Delivers live provisioning logs, deployment progress, and realtime console events.',
            'program' => env('PLATFORM_REVERB_PROGRAM', 'upentra-reverb'),
            'icon' => 'radio-tower',
        ],
    ],
];
