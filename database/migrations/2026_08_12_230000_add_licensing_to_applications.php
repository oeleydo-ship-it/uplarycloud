<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('license_type')->default('open_source')->after('documentation_url');
            $table->string('license_name')->nullable()->after('license_type');
            $table->string('pricing_model')->default('free')->after('license_name');
            $table->string('pricing_url')->nullable()->after('pricing_model');
            $table->boolean('requires_license')->default(false)->after('pricing_url');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn(['license_type', 'license_name', 'pricing_model', 'pricing_url', 'requires_license']);
        });
    }
};
