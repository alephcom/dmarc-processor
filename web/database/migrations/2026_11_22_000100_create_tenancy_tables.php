<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('retention_days_raw')->default(395);
            $table->boolean('require_mfa')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->text('app_authentication_secret')->nullable()->after('is_super_admin');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
            $table->string('sso_provider', 32)->nullable();
            $table->string('sso_id')->nullable();
            $table->unique(['sso_provider', 'sso_id']);
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('viewer'); // owner | admin | viewer
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_provider', 'sso_id']);
            $table->dropColumn(['is_super_admin', 'app_authentication_secret', 'app_authentication_recovery_codes', 'sso_provider', 'sso_id']);
        });
        Schema::dropIfExists('tenants');
    }
};
