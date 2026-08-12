<?php

return [
    // Prefer INFRASTRUCTURE_DRIVER=ssh in .env for live pre-checks; "fake" is for demos/tests.
    'driver' => env('INFRASTRUCTURE_DRIVER', 'fake'),
    'managed_driver' => env('MANAGED_INFRASTRUCTURE_DRIVER', 'fake'),
    'queues' => [
        'default' => 'default', 'provisioning' => 'provisioning',
        'deployments' => 'deployments', 'backups' => 'backups',
        'monitoring' => 'monitoring', 'networking' => 'networking', 'notifications' => 'notifications',
        'infrastructure' => 'infrastructure',
    ],
    // Queued DB rows with no Redis job (common after Redis restarts without AOF) are re-dispatched after this grace period.
    'orphaned_deployment_grace_seconds' => (int) env('ORPHANED_DEPLOYMENT_GRACE_SECONDS', 45),
    // Reserved Redis jobs older than this (while DB status is still queued) are ignored by recovery.
    'orphaned_reserved_job_seconds' => (int) env('ORPHANED_RESERVED_JOB_SECONDS', 120),
    // Dedicated deployments worker + background worker (see composer.dev / docker-compose).
    'worker_queues' => 'deployments,provisioning,infrastructure,networking,backups,notifications,monitoring,default',
    'worker_queues_deployments' => 'deployments',
    'worker_queues_background' => 'provisioning,infrastructure,networking,backups,notifications,monitoring,default',
    'supported_operating_systems' => ['ubuntu-22.04', 'ubuntu-24.04', 'debian-12'],
    // Docker work far outruns the SSH handshake timeout stored on the server record.
    'command_timeouts' => [
        'default' => (int) env('DOCKER_COMMAND_TIMEOUT', 180),
        'pull' => (int) env('DOCKER_PULL_TIMEOUT', 900),
        'build' => (int) env('DOCKER_BUILD_TIMEOUT', 900),
        'clone' => (int) env('GIT_CLONE_TIMEOUT', 300),
    ],
];
