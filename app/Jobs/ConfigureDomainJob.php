<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Networking\DomainNetworkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ConfigureDomainJob implements ShouldQueue
{
    use Queueable;
    public int $tries=2;
    public function __construct(public int $domainId,public int $tenantId){$this->onQueue(config('infrastructure.queues.networking'));}
    public function handle(DomainNetworkService $network): void
    {
        $domain = Domain::where('tenant_id', $this->tenantId)->findOrFail($this->domainId);
        try {
            $network->configure($domain);
            if ($domain->refresh()->ssl_enabled) {
                IssueCertificateJob::dispatchSync($domain->id, $domain->tenant_id);
            }
        } catch (Throwable $e) {
            $domain->update(['status' => 'failed', 'proxy_status' => 'failed', 'failure_reason' => $e->getMessage()]);
            throw $e;
        }
    }
}
