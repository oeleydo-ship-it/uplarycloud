<?php

namespace App\Services\Servers;

use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

class ControlPlaneKeyService
{
    public const GROUP = 'platform_ssh';

    public const PRIVATE_KEY = 'private_key';

    public const PUBLIC_KEY = 'public_key';

    /** @return array{private_key: string, public_key: string} */
    public function generate(): array
    {
        return $this->generateEd25519() ?? $this->generateRsa();
    }

    /**
     * Persistent deploy keypair for attaching custom servers (per tenant).
     * Private key is encrypted at rest in settings; public key is safe to display.
     *
     * @return array{private_key: string, public_key: string}
     */
    public function ensureForTenant(Tenant $tenant): array
    {
        $existing = $this->readTenantPair($tenant);
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($tenant): array {
            $locked = $this->readTenantPair($tenant);
            if ($locked !== null) {
                return $locked;
            }

            $pair = $this->generate();
            $this->writeTenantPair($tenant, $pair);

            return $pair;
        });
    }

    public function publicKeyForTenant(Tenant $tenant): string
    {
        return $this->ensureForTenant($tenant)['public_key'];
    }

    public function privateKeyForTenant(Tenant $tenant): string
    {
        return $this->ensureForTenant($tenant)['private_key'];
    }

    public function authorizeCommand(string $publicKey): string
    {
        $escaped = str_replace("'", "'\\''", trim($publicKey));

        return "mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '{$escaped}' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys";
    }

    /** @return array{private_key: string, public_key: string}|null */
    private function readTenantPair(Tenant $tenant): ?array
    {
        $rows = Setting::query()
            ->where('tenant_id', $tenant->id)
            ->where('group', self::GROUP)
            ->whereIn('key', [self::PRIVATE_KEY, self::PUBLIC_KEY])
            ->get()
            ->keyBy('key');

        $privateSetting = $rows->get(self::PRIVATE_KEY);
        $publicSetting = $rows->get(self::PUBLIC_KEY);

        if (! $privateSetting || ! $publicSetting || blank($privateSetting->value) || blank($publicSetting->value)) {
            return null;
        }

        try {
            $privateKey = $privateSetting->is_encrypted
                ? Crypt::decryptString((string) $privateSetting->value)
                : (string) $privateSetting->value;
        } catch (\Throwable) {
            return null;
        }

        if ($privateKey === '' || blank($publicSetting->value)) {
            return null;
        }

        return [
            'private_key' => $privateKey,
            'public_key' => (string) $publicSetting->value,
        ];
    }

    /** @param  array{private_key: string, public_key: string}  $pair */
    private function writeTenantPair(Tenant $tenant, array $pair): void
    {
        Setting::updateOrCreate(
            ['tenant_id' => $tenant->id, 'group' => self::GROUP, 'key' => self::PRIVATE_KEY],
            ['value' => Crypt::encryptString($pair['private_key']), 'is_encrypted' => true]
        );

        Setting::updateOrCreate(
            ['tenant_id' => $tenant->id, 'group' => self::GROUP, 'key' => self::PUBLIC_KEY],
            ['value' => $pair['public_key'], 'is_encrypted' => false]
        );
    }

    /** @return array{private_key: string, public_key: string}|null */
    private function generateEd25519(): ?array
    {
        try {
            $key = EC::createKey('Ed25519');
            $comment = 'uplary-cloud';

            return [
                'private_key' => $key->toString('OpenSSH', ['comment' => $comment]),
                'public_key' => trim($key->getPublicKey()->toString('OpenSSH', ['comment' => $comment])),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{private_key: string, public_key: string} */
    private function generateRsa(): array
    {
        $key = RSA::createKey(3072);
        $comment = 'uplary-cloud';

        return [
            'private_key' => $key->toString('OpenSSH', ['comment' => $comment]),
            'public_key' => trim($key->getPublicKey()->toString('OpenSSH', ['comment' => $comment])),
        ];
    }
}
