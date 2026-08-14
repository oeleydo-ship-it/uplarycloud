<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\Notifications\OperationalNotificationService;

class ServerObserver
{
    public function __construct(private readonly OperationalNotificationService $notifications) {}

    public function updated(Server $server): void
    {
        if ($server->wasChanged('status')) {
            $this->notifications->server($server);
        }
    }
}
