<?php
namespace App\Jobs;
use App\Models\Server;use App\Services\Infrastructure\InfrastructureBillingService;use Illuminate\Contracts\Queue\ShouldQueue;use Illuminate\Foundation\Queue\Queueable;
class AccrueManagedInfrastructureChargesJob implements ShouldQueue{use Queueable;public function __construct(){$this->onQueue(config('infrastructure.queues.infrastructure'));}public function handle(InfrastructureBillingService $billing):void{Server::where('server_type','managed')->whereNotIn('status',['offline','failed'])->with('managedPlan')->each(fn($server)=>$billing->accrue($server));}}
