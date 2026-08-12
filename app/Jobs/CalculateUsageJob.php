<?php
namespace App\Jobs;
use App\Models\Tenant;use App\Services\Billing\UsageService;use Illuminate\Contracts\Queue\ShouldQueue;use Illuminate\Foundation\Queue\Queueable;
class CalculateUsageJob implements ShouldQueue{use Queueable;public function __construct(public ?int $tenantId=null){$this->onQueue('monitoring');}public function handle(UsageService $service):void{Tenant::query()->when($this->tenantId,fn($q)=>$q->whereKey($this->tenantId))->each(fn($tenant)=>$service->collect($tenant));}}
