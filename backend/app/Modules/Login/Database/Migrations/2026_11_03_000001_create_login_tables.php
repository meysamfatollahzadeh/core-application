<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 63)->unique();
            $table->string('title');
            $table->boolean('is_active')->default(false);
            $table->jsonb('data');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid');
            $table->string('user_source_slug', 63);
            $table->foreign('user_source_slug')->references('slug')->on('user_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_source_slug', 'uuid']);
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('provider_sid')->nullable();
            $table->string('provider_config_hash', 64);
            $table->text('oidc_data');
            $table->timestamps();
            $table->index(['member_id', 'provider_sid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('members');
        Schema::dropIfExists('users');
        Schema::dropIfExists('user_sources');
    }
};
