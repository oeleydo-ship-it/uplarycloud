<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')->where('slug', 'nocodb')->update([
            'active' => false,
            'featured' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('applications')->where('slug', 'nocodb')->update([
            'active' => true,
            'updated_at' => now(),
        ]);
    }
};
