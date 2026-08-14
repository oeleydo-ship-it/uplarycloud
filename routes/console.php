<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('domains:renew-certificates')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('deployments:recover-orphaned')->everyMinute()->withoutOverlapping();
Schedule::job(new \App\Jobs\CollectOperationsMetricsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new \App\Jobs\EvaluateAlertRulesJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new \App\Jobs\DispatchScheduledBackupsJob)->everyTenMinutes()->withoutOverlapping();
Schedule::job(new \App\Jobs\CheckImageUpdatesJob)->dailyAt('02:40')->withoutOverlapping();
Schedule::job(new \App\Jobs\CalculateUsageJob)->hourlyAt(12)->withoutOverlapping();
Schedule::job(new \App\Jobs\AccrueManagedInfrastructureChargesJob)->dailyAt('00:20')->withoutOverlapping();
Schedule::job(new \App\Jobs\PublishScheduledBlogPostsJob)->everyMinute()->withoutOverlapping();
