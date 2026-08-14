<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class PlatformSettings
{
    public const SECRET_KEYS = ['smtp_password', 'stripe_secret', 'stripe_webhook_secret', 'paypal_client_secret', 'blog_ai_api_key'];

    public function group(string $group): array
    {
        return Setting::query()->whereNull('tenant_id')->where('group', $group)->get()->mapWithKeys(function (Setting $setting): array {
            $value = $setting->value;
            if ($setting->is_encrypted && filled($value)) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (\Throwable) {
                    $value = '';
                }
            }

            return [$setting->key => $value];
        })->all();
    }

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        return $this->group($group)[$key] ?? $default;
    }

    public function managedServersEnabled(): bool
    {
        return (bool) ((int) $this->get('general', 'managed_servers_enabled', 0));
    }

    public function featureEnabled(string $feature): bool
    {
        return (bool) ((int) $this->get('general', $feature.'_enabled', 1));
    }

    /**
     * Single source of truth for console email verification.
     * Superadmin General Settings toggle, or DISABLE_EMAIL_VERIFICATION=true / local auto-off.
     */
    public function emailVerificationRequired(): bool
    {
        if (config('app.disable_email_verification')) {
            return false;
        }

        return (bool) ((int) $this->get('general', 'email_verification', 1));
    }

    public function put(string $group, array $values): void
    {
        foreach ($values as $key => $value) {
            $secret = in_array($key, self::SECRET_KEYS, true);
            if ($secret && blank($value)) {
                continue;
            }
            Setting::updateOrCreate(
                ['tenant_id' => null, 'group' => $group, 'key' => $key],
                ['value' => $secret ? Crypt::encryptString((string) $value) : (is_bool($value) ? (int) $value : $value), 'is_encrypted' => $secret]
            );
        }
    }
}
