<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docker_containers', function (Blueprint $table): void {
            $table->foreignId('application_deployment_id')->nullable()->after('server_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'application_deployment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('docker_containers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('application_deployment_id');
        });
    }
};
