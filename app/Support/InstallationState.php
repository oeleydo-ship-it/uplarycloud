<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class InstallationState
{
    private ?bool $resolved = null;

    public function isInstalled(): bool
    {
        return $this->resolved ??= $this->resolve();
    }

    public function markInstalled(): void
    {
        Setting::updateOrCreate(
            ['tenant_id' => null, 'group' => 'platform', 'key' => 'installed'],
            ['value' => '1', 'is_encrypted' => false],
        );

        $this->resolved = true;
    }

    public function forget(): void
    {
        $this->resolved = null;
    }

    private function resolve(): bool
    {
        try {
            if (! Schema::hasTable('users')) {
                return false;
            }

            if (User::query()->exists()) {
                return true;
            }

            if (! Schema::hasTable('settings')) {
                return false;
            }

            return Setting::query()
                ->whereNull('tenant_id')
                ->where('group', 'platform')
                ->where('key', 'installed')
                ->where('value', '1')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
