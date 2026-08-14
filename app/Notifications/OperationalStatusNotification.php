<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OperationalStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $title,
        public readonly string $message,
        public readonly string $url,
        public readonly string $severity = 'info',
        public readonly string $resourceType = 'platform',
        public readonly ?string $resourceId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'severity' => $this->severity,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }
}
