<?php

namespace App\Services\Billing;

use App\Models\DockerContainer;
use App\Models\DockerVolume;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PlanLimitService
{
    public function plan(Tenant $tenant): Plan
    {
        $subscription = $tenant->entitledSubscription();

        if ($subscription?->plan) {
            return $subscription->plan;
        }

        $defaults = PlanCatalog::defaultsFor('free');

        return Plan::firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Core platform access',
                'monthly_price' => 0,
                'yearly_price' => 0,
                'currency' => 'USD',
                'limits' => $defaults['limits'],
                'gates' => $defaults['gates'],
                'features' => ['Core Docker management'],
                'active' => true,
            ]
        );
    }

    public function usage(Tenant $tenant, string $metric): float
    {
        return match ($metric) {
            'servers' => (float) $tenant->servers()->count(),
            'applications' => (float) $tenant->deployments()->count(),
            'containers' => (float) DockerContainer::query()->where('tenant_id', $tenant->id)->count(),
            'domains' => (float) $tenant->domains()->count(),
            'team_members' => (float) (
                $tenant->users()->wherePivot('is_active', true)->count()
                + $tenant->invitations()->where('status', 'pending')->where('expires_at', '>', now())->count()
            ),
            'volumes' => (float) DockerVolume::query()->where('tenant_id', $tenant->id)->count(),
            'volume_storage_gb' => (float) DockerVolume::query()->where('tenant_id', $tenant->id)->sum('size_bytes') / 1073741824,
            'backups' => (float) $tenant->backups()->count(),
            'backup_storage_gb' => (float) $tenant->backups()->sum('size_bytes') / 1073741824,
            'api_tokens' => (float) PersonalAccessToken::query()->where('tenant_id', $tenant->id)->whereNull('revoked_at')->count(),
            'managed_servers' => (float) $tenant->servers()->where('server_type', 'managed')->count(),
            default => 0,
        };
    }

    public function allows(Tenant $tenant, string $metric, float $additional = 1): bool
    {
        if ($this->hasSuperadminPrivilege()) {
            return true;
        }

        $limit = $this->plan($tenant)->limit($metric);

        return $limit === null || $this->usage($tenant, $metric) + $additional <= $limit;
    }

    public function enforce(Tenant $tenant, string $metric, float $additional = 1): void
    {
        if ($this->allows($tenant, $metric, $additional)) {
            return;
        }

        $label = PlanCatalog::label($metric);
        $limit = $this->plan($tenant)->limit($metric);
        $used = $this->usage($tenant, $metric);

        throw ValidationException::withMessages([
            $metric => "You've reached the {$label} limit on your current plan ({$this->format($used)} / {$this->format($limit)}). Upgrade your plan to continue.",
        ]);
    }

    public function allowsFeature(Tenant $tenant, string $feature): bool
    {
        if ($this->hasSuperadminPrivilege()) {
            return true;
        }

        return $this->plan($tenant)->allowsFeature($feature);
    }

    public function enforceFeature(Tenant $tenant, string $feature): void
    {
        if ($this->allowsFeature($tenant, $feature)) {
            return;
        }

        $label = PlanCatalog::label($feature);

        throw ValidationException::withMessages([
            $feature => "{$label} is not included in your current plan. Upgrade your plan to unlock this feature.",
        ]);
    }

    public function enforceDeployment(Tenant $tenant, string $type): void
    {
        $feature = match ($type) {
            'git' => 'git_deploy',
            'custom' => 'custom_docker',
            default => 'marketplace',
        };

        $this->enforceFeature($tenant, $feature);
        $this->enforce($tenant, 'applications');
        $this->enforce($tenant, 'containers');
        $this->enforce($tenant, 'volumes');
    }

    private function format(?float $value): string
    {
        if ($value === null) {
            return 'Unlimited';
        }

        return fmod($value, 1.0) === 0.0 ? (string) (int) $value : number_format($value, 1);
    }

    private function hasSuperadminPrivilege(): bool
    {
        if (Auth::user()?->is_super_admin) {
            return true;
        }

        $impersonatorId = session()->get('impersonator_id');

        return filled($impersonatorId)
            && User::query()->whereKey($impersonatorId)->where('is_super_admin', true)->exists();
    }
}
