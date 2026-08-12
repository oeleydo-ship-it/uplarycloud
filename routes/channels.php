<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenants.{tenantId}.servers.{serverUuid}', function ($user, $tenantId) {
    return $user->tenants()->whereKey($tenantId)->wherePivot('is_active', true)->exists();
});
Broadcast::channel('tenants.{tenantId}.docker', fn ($user,$tenantId) => $user->tenants()->whereKey($tenantId)->wherePivot('is_active',true)->exists());
Broadcast::channel('tenants.{tenantId}.deployments', fn ($user,$tenantId) => $user->tenants()->whereKey($tenantId)->wherePivot('is_active',true)->exists());
Broadcast::channel('tenants.{tenantId}.domains', fn ($user,$tenantId) => $user->tenants()->whereKey($tenantId)->wherePivot('is_active',true)->exists());
Broadcast::channel('tenants.{tenantId}.operations', fn ($user,$tenantId) => $user->tenants()->whereKey($tenantId)->wherePivot('is_active',true)->exists());
Broadcast::channel('tenants.{tenantId}.infrastructure', fn ($user,$tenantId) => $user->tenants()->whereKey($tenantId)->wherePivot('is_active',true)->exists());
