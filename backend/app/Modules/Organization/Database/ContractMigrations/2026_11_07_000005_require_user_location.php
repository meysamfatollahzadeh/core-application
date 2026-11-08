<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
        if (DB::table('users')->whereNull('building_area_id')->exists()) {
            throw new RuntimeException('Unassigned users remain. Run organization:prepare-locations --help, supply an explicit mapping or fallback, then organization:require-locations.');
        }
        DB::statement('ALTER TABLE users ALTER COLUMN building_area_id SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN building_area_id DROP NOT NULL');
    }
};
