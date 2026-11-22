<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-tenant overrides of alert settings; tenant_id NULL = MSP-wide defaults.
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->boolean('enabled')->default(true);
            $table->json('thresholds')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete(); // NULL = MSP-level alert
            $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('severity', 16); // info | warning | critical
            $table->string('dedupe_key'); // one open alert per key
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 16)->nullable(); // auto | manual | expired
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['dedupe_key', 'resolved_at']);
            $table->index(['tenant_id', 'resolved_at']);
            $table->index(['domain_id', 'resolved_at']);
        });

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete(); // NULL = MSP channel (gets all alerts)
            $table->string('name');
            $table->string('type', 16); // email | webhook
            $table->text('config'); // encrypted JSON: {addresses: [...]} or {url, secret}
            $table->json('alert_types')->nullable(); // null = all types
            $table->string('min_severity', 16)->default('warning');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('domains', function (Blueprint $table) {
            // When the published policy/pct last changed; drives the policy progression guide.
            $table->timestamp('policy_changed_at')->nullable()->after('current_pct');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('digest_frequency', 16)->default('weekly')->after('require_mfa'); // weekly | monthly | off
            $table->timestamp('last_digest_at')->nullable()->after('digest_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['digest_frequency', 'last_digest_at']));
        Schema::table('domains', fn (Blueprint $t) => $t->dropColumn('policy_changed_at'));
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rules');
    }
};
