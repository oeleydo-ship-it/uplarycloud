<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Networking\DomainNetworkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class IssueCertificateJob implements ShouldQueue
{
    use Queueable;
    public int $tries=6;
    public array $backoff=[10,20,40,80,120];
    public function __construct(public int $domainId,public int $tenantId){$this->onQueue(config('infrastructure.queues.networking'));}
    public function handle(DomainNetworkService $network): void{$domain=Domain::where('tenant_id',$this->tenantId)->findOrFail($this->domainId);$network->issueCertificate($domain);}
    public function failed(?Throwable $e): void{Domain::where('tenant_id',$this->tenantId)->whereKey($this->domainId)->update(['ssl_status'=>'failed','status'=>'failed','failure_reason'=>$e?->getMessage()]);}
}
