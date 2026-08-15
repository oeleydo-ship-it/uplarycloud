<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_server_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('managed_server_plan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('region', 60);
            $table->string('image', 100);
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('pending_payment');
            $table->string('stripe_checkout_session_id')->nullable()->index();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_server_orders');
    }
};
