<?php

namespace Tests\Feature;

use App\Services\Networking\AcmeEmailResolver;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AcmeEmailResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_placeholder_environment_email_falls_back_to_platform_support_email(): void
    {
        config([
            'networking.acme_email' => 'admin@example.com',
            'mail.from.address' => 'hello@example.com',
        ]);
        app(PlatformSettings::class)->put('general', ['support_email' => 'support@uplary.com']);

        $this->assertSame('support@uplary.com', app(AcmeEmailResolver::class)->resolve());
    }

    public function test_explicit_platform_certificate_email_has_priority(): void
    {
        config(['networking.acme_email' => 'environment@uplary.com']);
        app(PlatformSettings::class)->put('general', [
            'acme_email' => 'certificates@uplary.com',
            'support_email' => 'support@uplary.com',
        ]);

        $this->assertSame('certificates@uplary.com', app(AcmeEmailResolver::class)->resolve());
    }

    public function test_clear_error_is_returned_when_no_real_email_exists(): void
    {
        config([
            'networking.acme_email' => 'admin@example.com',
            'mail.from.address' => 'hello@example.com',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Superadmin > General Settings");

        app(AcmeEmailResolver::class)->resolve();
    }
}
