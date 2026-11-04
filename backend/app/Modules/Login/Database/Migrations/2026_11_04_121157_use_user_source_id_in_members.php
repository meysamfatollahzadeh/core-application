<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->foreignId('user_source_id')->nullable();
        });

        DB::statement('UPDATE members SET user_source_id = user_sources.id FROM user_sources WHERE members.user_source_slug = user_sources.slug');
        DB::statement('ALTER TABLE members ALTER COLUMN user_source_id SET NOT NULL');

        Schema::table('members', function (Blueprint $table): void {
            $table->foreign('user_source_id')->references('id')->on('user_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->index('user_source_id');
            $table->unique(['uuid', 'user_source_id']);
            $table->dropForeign(['user_source_slug']);
            $table->dropUnique(['user_source_slug', 'uuid']);
            $table->dropColumn('user_source_slug');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->string('user_source_slug', 63)->nullable();
        });

        DB::statement('UPDATE members SET user_source_slug = user_sources.slug FROM user_sources WHERE members.user_source_id = user_sources.id');
        DB::statement('ALTER TABLE members ALTER COLUMN user_source_slug SET NOT NULL');

        Schema::table('members', function (Blueprint $table): void {
            $table->foreign('user_source_slug')->references('slug')->on('user_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['user_source_slug', 'uuid']);
            $table->dropForeign(['user_source_id']);
            $table->dropUnique(['uuid', 'user_source_id']);
            $table->dropIndex(['user_source_id']);
            $table->dropColumn('user_source_id');
        });
    }
};
