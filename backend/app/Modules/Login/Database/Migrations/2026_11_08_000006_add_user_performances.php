<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->jsonb('performances')->default('{"theme":"auto"}');
        });
        DB::statement(<<<'SQL'
ALTER TABLE users ADD CONSTRAINT user_performances_theme CHECK (
    COALESCE(jsonb_typeof(performances) = 'object'
    AND jsonb_typeof(performances->'theme') = 'string'
    AND performances->>'theme' IN ('auto', 'light', 'dark'), false)
)
SQL);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('performances'));
    }
};
