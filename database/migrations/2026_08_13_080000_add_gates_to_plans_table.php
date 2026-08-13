<?php

use App\Models\Plan;
use App\Support\PlanCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('gates')->nullable()->after('features');
        });

        Plan::query()->each(function (Plan $plan): void {
            $defaults = PlanCatalog::defaultsFor($plan->slug);
            $plan->forceFill([
                'gates' => array_replace($defaults['gates'], $plan->gates ?? []),
                'limits' => array_replace($defaults['limits'], $plan->limits ?? []),
            ])->save();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('gates');
        });
    }
};
