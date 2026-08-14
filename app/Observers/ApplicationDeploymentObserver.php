<?php

namespace App\Observers;

use App\Models\ApplicationDeployment;
use App\Services\Notifications\OperationalNotificationService;

class ApplicationDeploymentObserver
{
    public function __construct(private readonly OperationalNotificationService $notifications) {}

    public function updated(ApplicationDeployment $deployment): void
    {
        if ($deployment->wasChanged('status')) {
            $this->notifications->deployment($deployment);
        }
    }
}
