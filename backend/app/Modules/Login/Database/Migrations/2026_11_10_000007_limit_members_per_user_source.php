<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Keep the preflight and constraint atomic against concurrent imports.
            DB::statement('LOCK TABLE members IN SHARE ROW EXCLUSIVE MODE');
            $conflicts = DB::table('members')->select('user_id', 'user_source_id')
                ->groupBy('user_id', 'user_source_id')->havingRaw('count(*) > 1')->get();
            if ($conflicts->isNotEmpty()) {
                throw new RuntimeException('Multiple Members for User/source; reconcile explicitly before migrating: '.$conflicts->toJson());
            }
            Schema::table('members', fn (Blueprint $table) => $table->unique(['user_id', 'user_source_id'], 'members_user_source_unique'));
        });
    }

    public function down(): void
    {
        Schema::table('members', fn (Blueprint $table) => $table->dropUnique('members_user_source_unique'));
    }
};
