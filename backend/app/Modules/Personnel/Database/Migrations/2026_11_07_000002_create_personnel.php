<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_positions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('position_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'position_id']);
            $t->index('position_id');
        });
        Schema::create('user_position_reports', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_position_id')->constrained()->cascadeOnDelete();
            $t->foreignId('report_to_position_id')->constrained('positions')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['user_position_id', 'report_to_position_id']);
            $t->index('report_to_position_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_position_reports');
        Schema::dropIfExists('user_positions');
    }
};
