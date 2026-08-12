<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name'); $table->text('description')->nullable();
            $table->string('provider', 32)->default('custom'); $table->string('server_group')->nullable();
            $table->json('tags')->nullable(); $table->ipAddress('ip_address'); $table->string('location')->nullable();
            $table->string('operating_system', 40); $table->string('server_type', 24)->default('byos');
            $table->string('status', 24)->default('pending')->index(); $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_username')->default('root'); $table->string('authentication_method', 20)->default('ssh_key');
            $table->unsignedSmallInteger('connection_timeout')->default(15);
            $table->unsignedSmallInteger('cpu_cores')->nullable(); $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedInteger('disk_gb')->nullable(); $table->string('docker_version')->nullable();
            $table->string('docker_compose_version')->nullable(); $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('provisioned_at')->nullable(); $table->text('failure_reason')->nullable();
            $table->timestamps(); $table->softDeletes();
            $table->unique(['tenant_id', 'name']); $table->index(['tenant_id', 'status']);
        });

        Schema::create('server_credentials', function (Blueprint $table) {
            $table->id(); $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('private_key')->nullable(); $table->text('password')->nullable(); $table->text('passphrase')->nullable();
            $table->timestamps();
        });

        Schema::create('server_provisioning_steps', function (Blueprint $table) {
            $table->id(); $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60); $table->string('label'); $table->unsignedTinyInteger('position');
            $table->string('status', 20)->default('pending'); $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['server_id', 'key']);
        });

        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id(); $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->decimal('cpu_percent', 5, 2); $table->decimal('memory_percent', 5, 2); $table->decimal('disk_percent', 5, 2);
            $table->decimal('load_average', 8, 2)->nullable(); $table->unsignedBigInteger('network_in_bytes')->default(0);
            $table->unsignedBigInteger('network_out_bytes')->default(0); $table->timestamp('recorded_at');
            $table->index(['server_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics'); Schema::dropIfExists('server_provisioning_steps');
        Schema::dropIfExists('server_credentials'); Schema::dropIfExists('servers');
    }
};
