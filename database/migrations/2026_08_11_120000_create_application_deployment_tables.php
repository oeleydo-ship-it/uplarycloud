<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_categories', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug')->unique(); $table->string('icon')->default('layout-grid'); $table->unsignedSmallInteger('position')->default(0); $table->timestamps();
        });
        Schema::create('applications', function (Blueprint $table): void {
            $table->id(); $table->foreignId('category_id')->constrained('application_categories'); $table->string('name'); $table->string('slug')->unique(); $table->text('description'); $table->string('icon')->default('box'); $table->string('accent')->default('#6c4cf5'); $table->string('website_url')->nullable(); $table->string('documentation_url')->nullable(); $table->string('docker_image'); $table->string('default_tag')->default('latest'); $table->unsignedInteger('default_port')->nullable(); $table->decimal('minimum_cpu', 4, 2)->default(0.25); $table->unsignedInteger('minimum_memory_mb')->default(512); $table->unsignedInteger('minimum_disk_gb')->default(1); $table->boolean('featured')->default(false); $table->boolean('active')->default(true); $table->boolean('verified')->default(true); $table->boolean('supports_domain')->default(true); $table->boolean('supports_ssl')->default(true); $table->boolean('supports_backup')->default(true); $table->unsignedInteger('install_count')->default(0); $table->timestamps();
        });
        Schema::create('application_templates', function (Blueprint $table): void {
            $table->id(); $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete(); $table->longText('compose_template'); $table->json('environment_schema')->nullable(); $table->json('volume_schema')->nullable(); $table->json('port_schema')->nullable(); $table->string('healthcheck')->nullable(); $table->string('restart_policy')->default('unless-stopped'); $table->text('installation_notes')->nullable(); $table->timestamps();
        });
        Schema::create('application_deployments', function (Blueprint $table): void {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('server_id')->constrained()->cascadeOnDelete(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('rolled_back_from_id')->nullable()->constrained('application_deployments')->nullOnDelete(); $table->string('name'); $table->string('slug'); $table->string('deployment_type')->default('marketplace'); $table->text('description')->nullable(); $table->string('docker_image'); $table->string('docker_tag')->default('latest'); $table->unsignedInteger('container_port')->nullable(); $table->string('domain')->nullable(); $table->decimal('cpu_limit', 5, 2)->nullable(); $table->unsignedInteger('memory_limit_mb')->nullable(); $table->unsignedInteger('disk_limit_gb')->nullable(); $table->boolean('auto_start')->default(true); $table->boolean('backup_enabled')->default(false); $table->string('restart_policy')->default('unless-stopped'); $table->string('status')->default('queued'); $table->unsignedTinyInteger('progress')->default(0); $table->string('current_stage')->default('queued'); $table->text('last_error')->nullable(); $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamp('deployed_at')->nullable(); $table->softDeletes(); $table->timestamps(); $table->unique(['tenant_id','slug']); $table->index(['tenant_id','status']);
        });
        Schema::create('deployment_environment_variables', function (Blueprint $table): void {
            $table->id();
            // Explicit short names: MySQL/MariaDB identifiers max 64 chars (defaults exceed that).
            $table->foreignId('application_deployment_id')->constrained(indexName: 'dev_env_vars_deployment_id_fk')->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('secret')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['application_deployment_id', 'key'], 'dev_env_vars_deployment_key_uq');
        });
        Schema::create('deployment_steps', function (Blueprint $table): void {
            $table->id(); $table->foreignId('application_deployment_id')->constrained()->cascadeOnDelete(); $table->string('key'); $table->string('name'); $table->unsignedSmallInteger('position'); $table->string('status')->default('pending'); $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->text('error')->nullable(); $table->timestamps(); $table->unique(['application_deployment_id','key']);
        });
        Schema::create('deployment_logs', function (Blueprint $table): void {
            $table->id(); $table->foreignId('application_deployment_id')->constrained()->cascadeOnDelete(); $table->string('level')->default('info'); $table->text('message'); $table->json('context')->nullable(); $table->timestamp('occurred_at'); $table->timestamps(); $table->index(['application_deployment_id','occurred_at']);
        });
        Schema::create('deployment_releases', function (Blueprint $table): void {
            $table->id(); $table->foreignId('application_deployment_id')->constrained()->cascadeOnDelete(); $table->string('version'); $table->string('image'); $table->string('image_tag'); $table->string('commit')->nullable(); $table->string('status')->default('successful'); $table->boolean('is_current')->default(false); $table->text('configuration')->nullable(); $table->timestamp('deployed_at'); $table->timestamp('rolled_back_at')->nullable(); $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_releases'); Schema::dropIfExists('deployment_logs'); Schema::dropIfExists('deployment_steps'); Schema::dropIfExists('deployment_environment_variables'); Schema::dropIfExists('application_deployments'); Schema::dropIfExists('application_templates'); Schema::dropIfExists('applications'); Schema::dropIfExists('application_categories');
    }
};
