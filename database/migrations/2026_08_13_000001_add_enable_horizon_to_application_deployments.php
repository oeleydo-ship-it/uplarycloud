<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_deployments', function (Blueprint $table): void {
            $table->boolean('enable_horizon')->default(false)->after('enable_reverb');
        });
    }

    public function down(): void
    {
        Schema::table('application_deployments', function (Blueprint $table): void {
            $table->dropColumn('enable_horizon');
        });
    }
};
