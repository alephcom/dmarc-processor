<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('dns_provider', 32)->nullable()->after('dkim_selectors');
            $table->timestamp('dns_checked_at')->nullable()->after('dns_provider');
            $table->timestamp('dns_verified_at')->nullable()->after('dns_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['dns_provider', 'dns_checked_at', 'dns_verified_at']);
        });
    }
};
