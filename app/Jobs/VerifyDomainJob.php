<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Networking\DomainNetworkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyDomainJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [30, 120];
    public function __construct(public int $domainId, public int $tenantId) { $this->onQueue(config('infrastructure.queues.networking')); }
    public function handle(DomainNetworkService $network): void
    {
        $domain = Domain::where('tenant_id', $this->tenantId)->findOrFail($this->domainId);
        if ($network->verifyDns($domain)) {
            // Configure must not wait on a queue worker — otherwise DNS stays Verified
            // while Traefik route / SSL remain Pending forever.
            ConfigureDomainJob::dispatch($domain->id, $domain->tenant_id);
        }
    }
}
