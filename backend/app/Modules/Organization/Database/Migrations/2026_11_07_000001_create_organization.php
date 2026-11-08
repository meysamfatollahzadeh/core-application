<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies', 'buildings'] as $name) {
            Schema::create($name, function (Blueprint $t): void {
                $t->id();
                $t->string('title');
                $t->timestamps();
            });
        }
        Schema::create('building_areas', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('building_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->index('building_id');
        });
        DB::statement('CREATE UNIQUE INDEX building_one_default ON building_areas (building_id) WHERE is_default');
        Schema::create('positions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('parent_position_id')->nullable();
            $t->string('title');
            $t->timestamps();
            $t->unique(['id', 'company_id']);
            $t->index('company_id');
            $t->index('parent_position_id');
            $t->foreign(['parent_position_id', 'company_id'])->references(['id', 'company_id'])->on('positions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE positions ADD CONSTRAINT position_not_own_parent CHECK (id <> parent_position_id)');
        Schema::table('users', function (Blueprint $t): void {
            $t->foreignId('building_area_id')->nullable()->constrained()->restrictOnDelete();
            $t->index('building_area_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('building_area_id'));
        Schema::dropIfExists('positions');
        Schema::dropIfExists('building_areas');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('companies');
    }
};
