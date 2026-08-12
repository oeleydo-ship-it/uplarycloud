<?php

namespace App\Services\Applications;

use App\Models\Application;
use Illuminate\Support\Str;

class CatalogEnvironmentFactory
{
    private const PLACEHOLDERS = ['', 'change-me', 'changeme', 'secret', 'password', 'generate', 'GENERATE'];

    /**
     * Resolve prefilled environment rows for the install wizard.
     *
     * @return list<array{key:string,value:string,description:?string,secret:bool}>
     */
    public function forApplication(?Application $application): array
    {
        if (! $application) {
            return $this->withGeneratedSecrets([
                ['key' => 'TZ', 'value' => 'Asia/Dubai', 'description' => 'Application timezone', 'secret' => false],
            ]);
        }

        $fromFactory = $this->schemaFor($application->slug);
        $fromTemplate = $application->template?->environment_schema;

        $schema = $fromFactory
            ?? (is_array($fromTemplate) && $fromTemplate !== [] ? $fromTemplate : null)
            ?? [['key' => 'TZ', 'value' => 'Asia/Dubai', 'description' => 'Application timezone', 'secret' => false]];

        return $this->withGeneratedSecrets($schema);
    }

    /**
     * Recommended environment schema for a catalog application slug.
     *
     * @return list<array{key:string,value:string,description:string,secret:bool}>|null
     */
    public function schemaFor(string $slug): ?array
    {
        $schemas = [
            'n8n' => [
                $this->tz(),
                ['key' => 'N8N_HOST', 'value' => 'n8n.example.com', 'description' => 'Public hostname', 'secret' => false],
                ['key' => 'N8N_PORT', 'value' => '5678', 'description' => 'Listen port', 'secret' => false],
                ['key' => 'N8N_PROTOCOL', 'value' => 'https', 'description' => 'Public protocol', 'secret' => false],
                ['key' => 'N8N_BASIC_AUTH_ACTIVE', 'value' => 'true', 'description' => 'Require basic auth', 'secret' => false],
                ['key' => 'N8N_BASIC_AUTH_USER', 'value' => 'admin', 'description' => 'Administrator username', 'secret' => false],
                ['key' => 'N8N_BASIC_AUTH_PASSWORD', 'value' => '', 'description' => 'Administrator password', 'secret' => true],
            ],
            'wordpress' => [
                $this->tz(),
                ['key' => 'WORDPRESS_DB_HOST', 'value' => 'db', 'description' => 'MySQL host — use "db" to auto-provision a MariaDB sidecar on the app network', 'secret' => false],
                ['key' => 'WORDPRESS_DB_USER', 'value' => 'wordpress', 'description' => 'Database username', 'secret' => false],
                ['key' => 'WORDPRESS_DB_PASSWORD', 'value' => '', 'description' => 'Database password', 'secret' => true],
                ['key' => 'WORDPRESS_DB_NAME', 'value' => 'wordpress', 'description' => 'Database name', 'secret' => false],
                ['key' => 'WORDPRESS_TABLE_PREFIX', 'value' => 'wp_', 'description' => 'Table prefix', 'secret' => false],
            ],
            'ghost' => [
                $this->tz(),
                ['key' => 'url', 'value' => 'http://localhost:2368', 'description' => 'Public site URL', 'secret' => false],
                ['key' => 'NODE_ENV', 'value' => 'production', 'description' => 'Runtime environment', 'secret' => false],
                ['key' => 'database__client', 'value' => 'sqlite3', 'description' => 'Database client', 'secret' => false],
                ['key' => 'database__connection__filename', 'value' => '/var/lib/ghost/content/data/ghost.db', 'description' => 'SQLite database path', 'secret' => false],
            ],
            'gitea' => [
                $this->tz(),
                ['key' => 'USER_UID', 'value' => '1000', 'description' => 'Runtime user id', 'secret' => false],
                ['key' => 'USER_GID', 'value' => '1000', 'description' => 'Runtime group id', 'secret' => false],
                ['key' => 'GITEA__database__DB_TYPE', 'value' => 'sqlite3', 'description' => 'Database type', 'secret' => false],
                ['key' => 'GITEA__security__INSTALL_LOCK', 'value' => 'false', 'description' => 'Lock installer after setup', 'secret' => false],
                ['key' => 'GITEA__server__DOMAIN', 'value' => 'localhost', 'description' => 'Public domain', 'secret' => false],
                ['key' => 'GITEA__server__ROOT_URL', 'value' => 'http://localhost:3000/', 'description' => 'Root URL', 'secret' => false],
            ],
            'nextcloud' => [
                $this->tz(),
                ['key' => 'SQLITE_DATABASE', 'value' => 'nextcloud', 'description' => 'SQLite database name', 'secret' => false],
                ['key' => 'NEXTCLOUD_ADMIN_USER', 'value' => 'admin', 'description' => 'Administrator username', 'secret' => false],
                ['key' => 'NEXTCLOUD_ADMIN_PASSWORD', 'value' => '', 'description' => 'Administrator password', 'secret' => true],
                ['key' => 'NEXTCLOUD_TRUSTED_DOMAINS', 'value' => 'localhost', 'description' => 'Trusted domains (space-separated)', 'secret' => false],
            ],
            'umami' => [
                $this->tz(),
                ['key' => 'DATABASE_URL', 'value' => 'postgresql://umami:change-me@db:5432/umami', 'description' => 'Postgres connection URL', 'secret' => true],
                ['key' => 'APP_SECRET', 'value' => '', 'description' => 'Application secret', 'secret' => true],
            ],
            'vaultwarden' => [
                $this->tz(),
                ['key' => 'ADMIN_TOKEN', 'value' => '', 'description' => 'Admin panel token', 'secret' => true],
                ['key' => 'SIGNUPS_ALLOWED', 'value' => 'false', 'description' => 'Allow public signups', 'secret' => false],
                ['key' => 'WEBSOCKET_ENABLED', 'value' => 'true', 'description' => 'Enable websocket notifications', 'secret' => false],
            ],
            'metabase' => [
                $this->tz(),
                ['key' => 'MB_DB_TYPE', 'value' => 'h2', 'description' => 'Embedded H2 database (use postgres/mysql in production)', 'secret' => false],
                ['key' => 'JAVA_TIMEZONE', 'value' => 'Asia/Dubai', 'description' => 'JVM timezone', 'secret' => false],
            ],
            'bookstack' => [
                $this->tz(),
                ['key' => 'APP_URL', 'value' => 'http://localhost', 'description' => 'Public application URL', 'secret' => false],
                ['key' => 'DB_HOST', 'value' => 'db', 'description' => 'Database host', 'secret' => false],
                ['key' => 'DB_PORT', 'value' => '3306', 'description' => 'Database port', 'secret' => false],
                ['key' => 'DB_USERNAME', 'value' => 'bookstack', 'description' => 'Database username', 'secret' => false],
                ['key' => 'DB_PASSWORD', 'value' => '', 'description' => 'Database password', 'secret' => true],
                ['key' => 'DB_DATABASE', 'value' => 'bookstack', 'description' => 'Database name', 'secret' => false],
            ],
            'sftpgo' => [
                $this->tz(),
                ['key' => 'SFTPGO_DEFAULT_ADMIN_USERNAME', 'value' => 'admin', 'description' => 'Administrator username', 'secret' => false],
                ['key' => 'SFTPGO_DEFAULT_ADMIN_PASSWORD', 'value' => '', 'description' => 'Administrator password', 'secret' => true],
            ],
            'open-webui' => [
                $this->tz(),
                ['key' => 'WEBUI_SECRET_KEY', 'value' => '', 'description' => 'Session signing secret', 'secret' => true],
                ['key' => 'ENABLE_SIGNUP', 'value' => 'true', 'description' => 'Allow first-user signup', 'secret' => false],
            ],
            'postgresql' => [
                $this->tz(),
                ['key' => 'POSTGRES_USER', 'value' => 'postgres', 'description' => 'Database superuser', 'secret' => false],
                ['key' => 'POSTGRES_PASSWORD', 'value' => '', 'description' => 'Database password', 'secret' => true],
                ['key' => 'POSTGRES_DB', 'value' => 'app', 'description' => 'Default database name', 'secret' => false],
            ],
            'mysql' => [
                $this->tz(),
                ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => '', 'description' => 'Root password', 'secret' => true],
                ['key' => 'MYSQL_DATABASE', 'value' => 'app', 'description' => 'Initial database', 'secret' => false],
                ['key' => 'MYSQL_USER', 'value' => 'app', 'description' => 'Application database user', 'secret' => false],
                ['key' => 'MYSQL_PASSWORD', 'value' => '', 'description' => 'Application database password', 'secret' => true],
            ],
            'redis' => [
                $this->tz(),
            ],
            'meilisearch' => [
                $this->tz(),
                ['key' => 'MEILI_ENV', 'value' => 'production', 'description' => 'Runtime environment', 'secret' => false],
                ['key' => 'MEILI_MASTER_KEY', 'value' => '', 'description' => 'Master API key', 'secret' => true],
            ],
            'grafana' => [
                $this->tz(),
                ['key' => 'GF_SECURITY_ADMIN_USER', 'value' => 'admin', 'description' => 'Administrator username', 'secret' => false],
                ['key' => 'GF_SECURITY_ADMIN_PASSWORD', 'value' => '', 'description' => 'Administrator password', 'secret' => true],
            ],
            'prometheus' => [$this->tz()],
            'mariadb' => [
                $this->tz(),
                ['key' => 'MARIADB_ROOT_PASSWORD', 'value' => '', 'description' => 'Root database password', 'secret' => true],
                ['key' => 'MARIADB_DATABASE', 'value' => 'app', 'description' => 'Initial database', 'secret' => false],
                ['key' => 'MARIADB_USER', 'value' => 'app', 'description' => 'Application database user', 'secret' => false],
                ['key' => 'MARIADB_PASSWORD', 'value' => '', 'description' => 'Application database password', 'secret' => true],
            ],
            'mongodb' => [
                $this->tz(),
                ['key' => 'MONGO_INITDB_ROOT_USERNAME', 'value' => 'admin', 'description' => 'Root username', 'secret' => false],
                ['key' => 'MONGO_INITDB_ROOT_PASSWORD', 'value' => '', 'description' => 'Root password', 'secret' => true],
                ['key' => 'MONGO_INITDB_DATABASE', 'value' => 'app', 'description' => 'Initial database', 'secret' => false],
            ],
            'rabbitmq' => [
                $this->tz(),
                ['key' => 'RABBITMQ_DEFAULT_USER', 'value' => 'admin', 'description' => 'Administrator username', 'secret' => false],
                ['key' => 'RABBITMQ_DEFAULT_PASS', 'value' => '', 'description' => 'Administrator password', 'secret' => true],
            ],
            'home-assistant' => [$this->tz()],
            'jellyfin' => [
                $this->tz(),
                ['key' => 'JELLYFIN_PublishedServerUrl', 'value' => 'http://localhost:8096', 'description' => 'Published server URL', 'secret' => false],
            ],
            'matomo' => [
                $this->tz(),
                ['key' => 'MATOMO_DATABASE_HOST', 'value' => 'db', 'description' => 'MariaDB host', 'secret' => false],
                ['key' => 'MATOMO_DATABASE_USERNAME', 'value' => 'matomo', 'description' => 'Database username', 'secret' => false],
                ['key' => 'MATOMO_DATABASE_PASSWORD', 'value' => '', 'description' => 'Database password', 'secret' => true],
                ['key' => 'MATOMO_DATABASE_DBNAME', 'value' => 'matomo', 'description' => 'Database name', 'secret' => false],
            ],
            'forgejo' => [
                $this->tz(),
                ['key' => 'USER_UID', 'value' => '1000', 'description' => 'Runtime user id', 'secret' => false],
                ['key' => 'USER_GID', 'value' => '1000', 'description' => 'Runtime group id', 'secret' => false],
                ['key' => 'FORGEJO__database__DB_TYPE', 'value' => 'sqlite3', 'description' => 'Database type', 'secret' => false],
                ['key' => 'FORGEJO__server__DOMAIN', 'value' => 'localhost', 'description' => 'Public domain', 'secret' => false],
            ],
            'roundcube' => [
                $this->tz(),
                ['key' => 'ROUNDCUBEMAIL_DEFAULT_HOST', 'value' => 'ssl://imap.example.com', 'description' => 'Default IMAP server', 'secret' => false],
                ['key' => 'ROUNDCUBEMAIL_SMTP_SERVER', 'value' => 'tls://smtp.example.com', 'description' => 'SMTP server', 'secret' => false],
            ],
            'gitlab-ee' => [
                $this->tz(),
                ['key' => 'GITLAB_ROOT_PASSWORD', 'value' => '', 'description' => 'Initial root password', 'secret' => true],
            ],
            'portainer-business' => [$this->tz()],
            'mattermost-enterprise' => [
                $this->tz(),
                ['key' => 'MM_SQLSETTINGS_DRIVERNAME', 'value' => 'postgres', 'description' => 'Database driver', 'secret' => false],
                ['key' => 'MM_SQLSETTINGS_DATASOURCE', 'value' => 'postgres://mattermost:change-me@db:5432/mattermost?sslmode=disable', 'description' => 'Postgres connection URL', 'secret' => true],
            ],
            'onlyoffice-enterprise' => [
                $this->tz(),
                ['key' => 'JWT_ENABLED', 'value' => 'true', 'description' => 'Protect document API with JWT', 'secret' => false],
                ['key' => 'JWT_SECRET', 'value' => '', 'description' => 'Document API signing secret', 'secret' => true],
            ],
            'uptime-kuma' => [
                $this->tz(),
            ],
        ];

        return $schemas[$slug] ?? null;
    }

    /**
     * @param  list<array<string,mixed>>  $schema
     * @return list<array{key:string,value:string,description:?string,secret:bool}>
     */
    public function withGeneratedSecrets(array $schema): array
    {
        $normalized = [];

        foreach ($schema as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $secret = (bool) ($row['secret'] ?? false);
            $value = (string) ($row['value'] ?? '');

            if ($secret && $this->needsGeneration($value)) {
                $generated = $this->generateSecret($key);
                if (in_array(trim($value), self::PLACEHOLDERS, true)) {
                    $value = $generated;
                } else {
                    $value = str_ireplace('change-me', $generated, $value);
                }
            }

            $normalized[] = [
                'key' => $key,
                'value' => $value,
                'description' => isset($row['description']) ? (string) $row['description'] : null,
                'secret' => $secret,
            ];
        }

        return array_values($normalized);
    }

    public function generateSecret(string $key = ''): string
    {
        if (str_contains(Str::upper($key), 'TOKEN') || str_contains(Str::upper($key), 'KEY') || str_contains(Str::upper($key), 'SECRET')) {
            return Str::password(32, symbols: false);
        }

        return Str::password(24);
    }

    private function needsGeneration(string $value): bool
    {
        return in_array(trim($value), self::PLACEHOLDERS, true)
            || str_contains(Str::lower($value), 'change-me');
    }

    /** @return array{key:string,value:string,description:string,secret:bool} */
    private function tz(): array
    {
        return ['key' => 'TZ', 'value' => 'Asia/Dubai', 'description' => 'Application timezone', 'secret' => false];
    }
}
