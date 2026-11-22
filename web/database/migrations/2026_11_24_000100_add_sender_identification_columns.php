<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_intel', function (Blueprint $table) {
            // rDNS is only trusted for sender matching when the PTR name resolves back to the IP.
            $table->boolean('rdns_verified')->default(false)->after('rdns');
            $table->string('match_reason', 32)->nullable()->after('known_sender_id'); // rdns | spf | asn
        });

        Schema::table('daily_source_stats', function (Blueprint $table) {
            // "sender:<id>" | "asn:<n>" | "ip:<addr>" — updated when an IP's identification improves.
            $table->string('source_key')->default('')->after('source_ip');
            $table->index(['domain_id', 'source_key', 'day']);
        });

        Schema::table('domain_sources', function (Blueprint $table) {
            $table->string('label')->nullable()->after('source_key');
        });
    }

    public function down(): void
    {
        Schema::table('domain_sources', fn (Blueprint $t) => $t->dropColumn('label'));
        Schema::table('daily_source_stats', function (Blueprint $t) {
            $t->dropIndex(['domain_id', 'source_key', 'day']);
            $t->dropColumn('source_key');
        });
        Schema::table('ip_intel', fn (Blueprint $t) => $t->dropColumn(['rdns_verified', 'match_reason']));
    }
};
