<?php

namespace Tests\Unit;

use App\Services\Applications\CatalogEnvironmentFactory;
use PHPUnit\Framework\TestCase;

class CatalogEnvironmentFactoryTest extends TestCase
{
    public function test_wordpress_schema_includes_database_credentials(): void
    {
        $factory = new CatalogEnvironmentFactory;
        $schema = $factory->schemaFor('wordpress');

        $this->assertNotNull($schema);
        $keys = array_column($schema, 'key');
        $this->assertContains('WORDPRESS_DB_HOST', $keys);
        $this->assertContains('WORDPRESS_DB_USER', $keys);
        $this->assertContains('WORDPRESS_DB_PASSWORD', $keys);
        $this->assertContains('WORDPRESS_DB_NAME', $keys);

        $password = collect($schema)->firstWhere('key', 'WORDPRESS_DB_PASSWORD');
        $this->assertTrue($password['secret']);
    }

    public function test_with_generated_secrets_fills_empty_secret_values(): void
    {
        $factory = new CatalogEnvironmentFactory;
        $rows = $factory->withGeneratedSecrets([
            ['key' => 'TZ', 'value' => 'Asia/Dubai', 'description' => 'Timezone', 'secret' => false],
            ['key' => 'ADMIN_PASSWORD', 'value' => 'change-me', 'description' => 'Password', 'secret' => true],
            ['key' => 'APP_SECRET', 'value' => '', 'description' => 'Secret', 'secret' => true],
            ['key' => 'DATABASE_URL', 'value' => 'postgresql://umami:change-me@db:5432/umami', 'description' => 'DB URL', 'secret' => true],
        ]);

        $this->assertSame('Asia/Dubai', $rows[0]['value']);
        $this->assertNotSame('change-me', $rows[1]['value']);
        $this->assertNotSame('', $rows[2]['value']);
        $this->assertTrue($rows[1]['secret']);
        $this->assertTrue($rows[2]['secret']);
        $this->assertGreaterThanOrEqual(16, strlen($rows[1]['value']));
        $this->assertStringStartsWith('postgresql://umami:', $rows[3]['value']);
        $this->assertStringEndsWith('@db:5432/umami', $rows[3]['value']);
        $this->assertStringNotContainsString('change-me', $rows[3]['value']);
    }

    public function test_joomla_schema_includes_database_credentials(): void
    {
        $factory = new CatalogEnvironmentFactory;
        $schema = $factory->schemaFor('joomla');

        $this->assertNotNull($schema);
        $keys = array_column($schema, 'key');
        $this->assertContains('JOOMLA_DB_HOST', $keys);
        $this->assertContains('JOOMLA_DB_PASSWORD', $keys);
    }

    public function test_directus_schema_defaults_to_sqlite(): void
    {
        $factory = new CatalogEnvironmentFactory;
        $schema = $factory->schemaFor('directus');

        $this->assertNotNull($schema);
        $client = collect($schema)->firstWhere('key', 'DB_CLIENT');
        $this->assertSame('sqlite3', $client['value']);
    }
}
