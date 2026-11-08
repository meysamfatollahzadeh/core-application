<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['roles', 'permissions'] as $name) {
            Schema::create($name, function (Blueprint $t): void {
                $t->id();
                $t->string('key', 100)->unique();
                $t->string('title');
                $t->timestamps();
            });
        }
        Schema::create('role_permission', function (Blueprint $t): void {
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->foreignId('permission_id')->constrained()->restrictOnDelete();
            $t->primary(['role_id', 'permission_id']);
            $t->index('permission_id');
        });
        Schema::create('user_role', function (Blueprint $t): void {
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->primary(['user_id', 'role_id']);
            $t->index('role_id');
        });
        Schema::create('company_user_role', function (Blueprint $t): void {
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->primary(['company_id', 'user_id', 'role_id']);
            $t->index(['user_id', 'company_id']);
            $t->index('role_id');
        });
        Schema::create('position_role', function (Blueprint $t): void {
            $t->foreignId('position_id')->constrained()->restrictOnDelete();
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->string('scope', 10);
            $t->primary(['position_id', 'role_id', 'scope']);
            $t->index('role_id');
        });
        DB::statement("ALTER TABLE position_role ADD CONSTRAINT valid_position_role_scope CHECK (scope IN ('company','global'))");
    }

    public function down(): void
    {
        foreach (['position_role', 'company_user_role', 'user_role', 'role_permission', 'permissions', 'roles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
