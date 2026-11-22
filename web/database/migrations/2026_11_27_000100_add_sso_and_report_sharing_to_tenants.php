<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Single sign-on: which identities may sign in (and be created just-in-time) for this tenant.
            $table->json('sso_domains')->nullable();               // e.g. ["acme.com"]
            $table->json('sso_microsoft_tenant_ids')->nullable();  // Entra directory (tenant) IDs
            $table->string('sso_default_role', 16)->default('viewer');
            // Bumping this invalidates every shared report link for the tenant.
            $table->unsignedInteger('report_share_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['sso_domains', 'sso_microsoft_tenant_ids', 'sso_default_role', 'report_share_version']));
    }
};
