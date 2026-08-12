<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('provider_connections', function (Blueprint $table) {
            $table->id();$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();$table->uuid('uuid')->unique();
            $table->string('name');$table->string('provider',32);$table->text('api_token')->nullable();$table->text('api_secret')->nullable();
            $table->string('account_id')->nullable();$table->json('credentials')->nullable();$table->boolean('active')->default(true);
            $table->timestamp('last_verified_at')->nullable();$table->string('last_error')->nullable();$table->timestamps();
            $table->unique(['tenant_id','name']);$table->index(['tenant_id','provider','active']);
        });

        Schema::create('managed_server_plans', function (Blueprint $table) {
            $table->id();$table->uuid('uuid')->unique();$table->string('provider',32);$table->string('provider_plan_id');$table->string('name');
            $table->string('category',32)->default('general');$table->unsignedSmallInteger('cpu_cores');$table->unsignedInteger('memory_mb');
            $table->unsignedInteger('disk_gb');$table->unsignedInteger('bandwidth_gb')->default(0);$table->unsignedInteger('monthly_cost')->default(0);
            $table->unsignedInteger('monthly_price')->default(0);$table->char('currency',3)->default('USD');$table->json('regions');
            $table->json('images')->nullable();$table->boolean('featured')->default(false);$table->boolean('active')->default(true);$table->unsignedSmallInteger('position')->default(0);$table->timestamps();
            $table->unique(['provider','provider_plan_id']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('provider_connection_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->foreignId('managed_server_plan_id')->nullable()->after('provider_connection_id')->constrained()->nullOnDelete();
            $table->string('provider_resource_id')->nullable()->after('provider');$table->string('provider_region')->nullable()->after('location');
            $table->string('provider_image')->nullable()->after('operating_system');$table->timestamp('provider_created_at')->nullable();
            $table->index(['tenant_id','server_type','status']);
        });

        Schema::create('infrastructure_operations', function (Blueprint $table) {
            $table->id();$table->uuid('uuid')->unique();$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();$table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action',32);$table->string('status',24)->default('pending');$table->string('idempotency_key',64)->unique();
            $table->json('parameters')->nullable();$table->json('provider_response')->nullable();$table->text('log')->nullable();$table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();$table->timestamp('completed_at')->nullable();$table->timestamps();$table->index(['tenant_id','status','created_at']);
        });

        Schema::create('infrastructure_charges', function (Blueprint $table) {
            $table->id();$table->uuid('uuid')->unique();$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();$table->foreignId('infrastructure_operation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('charge_type',32);$table->string('description');$table->decimal('quantity',12,4)->default(1);
            $table->unsignedInteger('unit_amount');$table->unsignedInteger('total');$table->char('currency',3)->default('USD');
            $table->string('status',24)->default('pending');$table->timestamp('period_starts_at')->nullable();$table->timestamp('period_ends_at')->nullable();
            $table->timestamp('billed_at')->nullable();$table->json('metadata')->nullable();$table->timestamps();$table->index(['tenant_id','status','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infrastructure_charges');Schema::dropIfExists('infrastructure_operations');
        Schema::table('servers',function(Blueprint $table){$table->dropForeign(['provider_connection_id']);$table->dropForeign(['managed_server_plan_id']);$table->dropColumn(['provider_connection_id','managed_server_plan_id','provider_resource_id','provider_region','provider_image','provider_created_at']);});
        Schema::dropIfExists('managed_server_plans');Schema::dropIfExists('provider_connections');
    }
};
