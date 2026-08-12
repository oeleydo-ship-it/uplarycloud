<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->unsignedInteger('monthly_price')->default(0);
            $table->unsignedInteger('yearly_price')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('stripe_monthly_price_id')->nullable();
            $table->string('stripe_yearly_price_id')->nullable();
            $table->json('limits');
            $table->json('features')->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('active');
            $table->string('billing_cycle', 16)->default('monthly');
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('number')->nullable();
            $table->string('stripe_invoice_id')->nullable()->unique();
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('tax')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->string('hosted_invoice_url', 1000)->nullable();
            $table->string('invoice_pdf', 1000)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->json('line_items')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('stripe_payment_method_id')->nullable()->unique();
            $table->string('type', 24)->default('card');
            $table->string('brand', 32)->nullable();
            $table->string('last_four', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 60);
            $table->decimal('quantity', 16, 4)->default(0);
            $table->string('unit', 24);
            $table->timestamp('period_starts_at');
            $table->timestamp('period_ends_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'metric', 'period_starts_at']);
            $table->index(['tenant_id', 'period_ends_at']);
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('email');
            $table->string('role', 32);
            $table->string('token_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
        });

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->string('environment', 24)->default('production');
                $table->json('ip_restrictions')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
                $table->index(['tenant_id', 'created_at']);
            });
        } else {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('tokenable_id')->constrained()->cascadeOnDelete();
                $table->string('environment', 24)->default('production')->after('abilities');
                $table->json('ip_restrictions')->nullable()->after('environment');
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
