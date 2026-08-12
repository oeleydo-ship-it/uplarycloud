<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('hostname')->unique();
            $table->string('redirect_to')->nullable();
            $table->boolean('force_https')->default(true);
            $table->boolean('ssl_enabled')->default(true);
            $table->boolean('auto_renew')->default(true);
            $table->string('status')->default('pending');
            $table->string('dns_status')->default('pending');
            $table->string('dns_record_type')->default('A');
            $table->string('expected_value');
            $table->json('resolved_values')->nullable();
            $table->timestamp('last_dns_check_at')->nullable();
            $table->timestamp('dns_verified_at')->nullable();
            $table->string('proxy_status')->default('pending');
            $table->timestamp('proxy_configured_at')->nullable();
            $table->string('ssl_status')->default('pending');
            $table->string('certificate_provider')->default("Let's Encrypt");
            $table->string('certificate_serial')->nullable();
            $table->timestamp('certificate_issued_at')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->timestamp('last_renewal_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['ssl_status', 'certificate_expires_at']);
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->string('proxy_status')->default('not_installed');
            $table->string('proxy_version')->nullable();
            $table->string('proxy_network')->nullable();
            $table->timestamp('proxy_installed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['proxy_status', 'proxy_version', 'proxy_network', 'proxy_installed_at']);
        });
        Schema::dropIfExists('domains');
    }
};
