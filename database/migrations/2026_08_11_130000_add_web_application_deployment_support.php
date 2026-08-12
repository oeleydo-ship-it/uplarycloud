<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_packs', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('slug')->unique(); $table->string('framework'); $table->string('icon')->default('code-2'); $table->string('accent')->default('#6c4cf5'); $table->json('detectors'); $table->json('runtime_versions'); $table->json('defaults'); $table->boolean('active')->default(true); $table->timestamps(); });
        Schema::table('application_deployments', function (Blueprint $table): void {
            $table->foreignId('build_pack_id')->nullable()->after('application_id')->constrained()->nullOnDelete(); $table->string('framework')->nullable()->after('deployment_type'); $table->string('git_provider')->nullable(); $table->string('repository_url')->nullable(); $table->string('branch')->default('main'); $table->string('commit_hash')->nullable(); $table->text('deploy_key')->nullable(); $table->string('runtime_version')->nullable(); $table->string('root_directory')->default('/'); $table->string('package_manager')->nullable(); $table->string('install_command')->nullable(); $table->string('build_command')->nullable(); $table->string('start_command')->nullable(); $table->string('output_directory')->nullable(); $table->string('database_engine')->nullable(); $table->boolean('enable_redis')->default(false); $table->boolean('enable_queue')->default(false); $table->boolean('enable_scheduler')->default(false); $table->boolean('auto_deploy')->default(false); $table->text('webhook_secret')->nullable(); $table->string('build_status')->nullable(); $table->timestamp('last_webhook_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('application_deployments', function (Blueprint $table): void { $table->dropConstrainedForeignId('build_pack_id'); $table->dropColumn(['framework','git_provider','repository_url','branch','commit_hash','deploy_key','runtime_version','root_directory','package_manager','install_command','build_command','start_command','output_directory','database_engine','enable_redis','enable_queue','enable_scheduler','auto_deploy','webhook_secret','build_status','last_webhook_at']); }); Schema::dropIfExists('build_packs');
    }
};
