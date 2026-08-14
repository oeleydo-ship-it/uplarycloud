<?php

namespace App\Services\Notifications;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Models\ApplicationDeployment;
use App\Models\Server;
use App\Notifications\OperationalStatusNotification;
use Illuminate\Support\Facades\Notification;

class OperationalNotificationService
{
    public function deployment(ApplicationDeployment $deployment): void
    {
        $status = $deployment->status;
        if (! in_array($status, [DeploymentStatus::Running, DeploymentStatus::Failed], true)) {
            return;
        }

        $successful = $status === DeploymentStatus::Running;
        $this->send(
            $deployment->tenant_id,
            $successful ? 'Deployment completed' : 'Deployment failed',
            $successful
                ? $deployment->name.' is running successfully.'
                : $deployment->name.' failed'.($deployment->last_error ? ': '.str($deployment->last_error)->limit(180) : '.'),
            route('deployments.show', $deployment, absolute: false),
            $successful ? 'success' : 'error',
            'deployment',
            $deployment->uuid,
        );
    }

    public function server(Server $server): void
    {
        $status = $server->status;
        if (! in_array($status, [ServerStatus::Online, ServerStatus::Failed], true)) {
            return;
        }

        $successful = $status === ServerStatus::Online;
        $this->send(
            $server->tenant_id,
            $successful ? 'Server is online' : 'Server provisioning failed',
            $successful
                ? $server->name.' finished provisioning and is online.'
                : $server->name.' failed'.($server->failure_reason ? ': '.str($server->failure_reason)->limit(180) : '.'),
            route($successful ? 'servers.details' : 'servers.provisioning', $server, absolute: false),
            $successful ? 'success' : 'error',
            'server',
            $server->uuid,
        );
    }

    private function send(int $tenantId, string $title, string $message, string $url, string $severity, string $resourceType, ?string $resourceId): void
    {
        $users = \App\Models\Tenant::query()->find($tenantId)?->users()
            ->wherePivot('is_active', true)
            ->wherePivotIn('role', ['owner', 'admin', 'developer'])
            ->get();

        if ($users?->isEmpty() ?? true) {
            return;
        }

        Notification::send($users, new OperationalStatusNotification(
            $tenantId,
            $title,
            $message,
            $url,
            $severity,
            $resourceType,
            $resourceId,
        ));
    }
}
