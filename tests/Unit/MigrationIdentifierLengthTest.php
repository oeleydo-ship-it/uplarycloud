<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MySQL/MariaDB rejects identifier names longer than 64 characters.
 * Laravel's default FK/index names are "{table}_{columns}_{type}".
 */
class MigrationIdentifierLengthTest extends TestCase
{
    private const MYSQL_IDENTIFIER_LIMIT = 64;

    /**
     * @return array<string, array{0: string}>
     */
    public static function explicitShortNames(): array
    {
        return [
            'deployment env vars FK' => ['dev_env_vars_deployment_id_fk'],
            'deployment env vars unique' => ['dev_env_vars_deployment_key_uq'],
            'container volume unique' => ['ctr_vol_pair_uq'],
            'container network unique' => ['ctr_net_pair_uq'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function defaultNamesThatMustStayShortOrBeOverridden(): array
    {
        return [
            // These defaults exceed 64 and must keep explicit short names in migrations.
            'deployment_environment_variables FK' => [
                'deployment_environment_variables_application_deployment_id_foreign',
            ],
            'deployment_environment_variables unique' => [
                'deployment_environment_variables_application_deployment_id_key_unique',
            ],
        ];
    }

    #[DataProvider('explicitShortNames')]
    public function test_explicit_constraint_names_fit_mysql_limit(string $name): void
    {
        $this->assertLessThanOrEqual(
            self::MYSQL_IDENTIFIER_LIMIT,
            strlen($name),
            "Constraint name [{$name}] exceeds MySQL's 64-character identifier limit."
        );
    }

    #[DataProvider('defaultNamesThatMustStayShortOrBeOverridden')]
    public function test_known_long_default_names_still_exceed_limit(string $defaultName): void
    {
        $this->assertGreaterThan(
            self::MYSQL_IDENTIFIER_LIMIT,
            strlen($defaultName),
            "Expected [{$defaultName}] to remain longer than 64 so migrations keep using short aliases."
        );
    }

    public function test_remaining_default_fk_and_index_candidates_fit_mysql_limit(): void
    {
        $candidates = [
            'deployment_steps_application_deployment_id_foreign',
            'deployment_steps_application_deployment_id_key_unique',
            'deployment_logs_application_deployment_id_foreign',
            'deployment_logs_application_deployment_id_occurred_at_index',
            'deployment_releases_application_deployment_id_foreign',
            'application_deployments_rolled_back_from_id_foreign',
            'docker_containers_application_deployment_id_foreign',
            'docker_containers_tenant_id_application_deployment_id_index',
            'support_tickets_application_deployment_id_foreign',
            'backup_schedules_application_deployment_id_foreign',
            'backup_schedules_backup_destination_id_foreign',
            'alert_rules_application_deployment_id_foreign',
            'operational_logs_application_deployment_id_foreign',
            'domains_application_deployment_id_foreign',
            'infrastructure_charges_infrastructure_operation_id_foreign',
            'infrastructure_operations_tenant_id_status_created_at_index',
            'infrastructure_charges_tenant_id_status_created_at_index',
            'container_metrics_docker_container_id_recorded_at_index',
            'usage_records_tenant_id_metric_period_starts_at_unique',
        ];

        foreach ($candidates as $name) {
            $this->assertLessThanOrEqual(
                self::MYSQL_IDENTIFIER_LIMIT,
                strlen($name),
                "Default identifier [{$name}] (".strlen($name)." chars) exceeds MySQL's 64-character limit; give it an explicit short name."
            );
        }
    }
}
