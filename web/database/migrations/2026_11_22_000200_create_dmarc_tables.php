<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name')->unique();
            $table->string('rua_token', 16)->unique();
            $table->string('status', 16)->default('pending'); // pending | active | paused
            $table->string('onboarding_step', 32)->default('scan');
            $table->string('current_policy', 16)->nullable();
            $table->unsignedTinyInteger('current_pct')->nullable();
            $table->string('target_policy', 16)->default('reject');
            $table->json('dkim_selectors')->nullable(); // user-supplied extra selectors
            $table->timestamp('first_report_at')->nullable();
            $table->timestamp('last_report_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('spool_id', 64);
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rua_token', 16);
            $table->string('remote_ip', 45)->nullable();
            $table->string('mail_from')->nullable();
            $table->string('subject')->nullable();
            $table->string('raw_path');
            $table->unsignedInteger('size');
            $table->string('status', 16)->default('pending'); // pending | processed | ignored | quarantined | failed
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('reports_count')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['spool_id', 'rua_token']); // idempotent ingest on receiver retries
            $table->index(['status', 'created_at']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbound_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reporter_org');
            $table->string('reporter_email')->nullable();
            $table->string('report_id');
            $table->string('policy_domain');
            $table->timestamp('date_begin');
            $table->timestamp('date_end');
            $table->json('policy_published');
            $table->json('errors')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();
            $table->unique(['reporter_org', 'report_id', 'policy_domain'], 'reports_dedupe_unique');
            $table->index(['domain_id', 'date_begin']);
        });

        Schema::create('report_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('source_ip', 45);
            $table->unsignedInteger('count');
            $table->string('disposition', 16);
            $table->boolean('dkim_aligned');
            $table->boolean('spf_aligned');
            $table->string('header_from');
            $table->string('envelope_from')->nullable();
            $table->string('envelope_to')->nullable();
            $table->json('dkim_results')->nullable();
            $table->json('spf_results')->nullable();
            $table->json('reasons')->nullable();
            $table->index(['domain_id', 'day']);
            $table->index(['domain_id', 'source_ip', 'day']);
        });

        Schema::create('daily_domain_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('dmarc_pass')->default(0);
            $table->unsignedBigInteger('dkim_aligned')->default(0);
            $table->unsignedBigInteger('spf_aligned')->default(0);
            $table->unsignedBigInteger('quarantined')->default(0);
            $table->unsignedBigInteger('rejected')->default(0);
            $table->unsignedInteger('reports')->default(0);
            $table->unique(['domain_id', 'day']);
        });

        Schema::create('daily_source_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('source_ip', 45);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('dmarc_pass')->default(0);
            $table->unsignedBigInteger('dkim_aligned')->default(0);
            $table->unsignedBigInteger('spf_aligned')->default(0);
            $table->unsignedBigInteger('quarantined')->default(0);
            $table->unsignedBigInteger('rejected')->default(0);
            $table->unique(['domain_id', 'day', 'source_ip']);
            $table->index(['source_ip']);
        });

        Schema::create('known_senders', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('category', 32)->nullable(); // mailbox | esp | marketing | crm | security | other
            $table->json('asns')->nullable();
            $table->json('rdns_suffixes')->nullable();
            $table->json('spf_includes')->nullable();
            $table->json('dkim_domains')->nullable();
            $table->string('docs_url')->nullable();
            $table->timestamps();
        });

        Schema::create('ip_intel', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->string('rdns')->nullable();
            $table->unsignedInteger('asn')->nullable();
            $table->string('as_org')->nullable();
            $table->string('country', 2)->nullable();
            $table->foreignId('known_sender_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('domain_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('source_key'); // "sender:<id>" | "asn:<n>" | "ip:<addr>"
            $table->string('classification', 16)->default('unknown'); // legit | unknown | suspicious
            $table->text('notes')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['domain_id', 'source_key']);
        });

        Schema::create('dns_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8); // dmarc | spf | dkim
            $table->text('raw')->nullable();
            $table->json('parsed')->nullable();
            $table->json('issues')->nullable();
            $table->string('status', 8); // ok | warn | error | missing
            $table->timestamp('checked_at');
            $table->index(['domain_id', 'type', 'checked_at']);
        });
    }

    public function down(): void
    {
        foreach (['dns_checks', 'domain_sources', 'ip_intel', 'known_senders', 'daily_source_stats', 'daily_domain_stats', 'report_records', 'reports', 'inbound_messages', 'domains'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
