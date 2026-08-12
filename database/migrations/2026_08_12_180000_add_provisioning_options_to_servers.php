<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->boolean('install_docker')->default(true);
            $table->boolean('install_proxy')->default(true);
            $table->boolean('install_monitoring')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn(['install_docker','install_proxy','install_monitoring']));
    }
};
