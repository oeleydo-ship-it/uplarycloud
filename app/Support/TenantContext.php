<?php

namespace App\Support;

use App\Models\Tenant;
use RuntimeException;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function current(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('No tenant is active for this request.');
    }

    public function id(): int
    {
        return $this->current()->getKey();
    }
}
