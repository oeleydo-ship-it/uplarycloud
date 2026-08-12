<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function view(User $user, Server $server): bool { return $this->role($user, $server)?->value !== null; }
    public function create(User $user): bool { return $user->tenants()->whereKey(session('tenant_id'))->wherePivotIn('role', ['owner', 'admin'])->exists(); }
    public function update(User $user, Server $server): bool { return in_array($this->role($user, $server), [MembershipRole::Owner, MembershipRole::Admin], true); }
    public function delete(User $user, Server $server): bool { return $this->role($user, $server) === MembershipRole::Owner; }
    public function operate(User $user, Server $server): bool { return in_array($this->role($user, $server), [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Developer], true); }

    private function role(User $user, Server $server): ?MembershipRole
    {
        $membership = $user->tenants()->whereKey($server->tenant_id)->wherePivot('is_active', true)->first();
        return $membership ? MembershipRole::tryFrom($membership->pivot->role) : null;
    }
}
