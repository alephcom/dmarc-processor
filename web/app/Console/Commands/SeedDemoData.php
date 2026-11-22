<?php

namespace App\Console\Commands;

use App\Alerts\AlertEvaluator;
use App\Intel\SourceRegistry;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Models\IpIntel;
use App\Models\KnownSender;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StatsAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Local development only: a demo customer with 30 days of realistic report data,
 * so the dashboards can be explored without real mail flow.
 */
class SeedDemoData extends Command
{
    protected $signature = 'dmarc:demo';

    protected $description = 'Create a demo customer with sample report data (local environment only)';

    public function handle(StatsAggregator $stats, SourceRegistry $registry): int
    {
        if (! app()->environment('local')) {
            $this->error('dmarc:demo only runs when APP_ENV=local.');

            return self::FAILURE;
        }

        $tenant = Tenant::firstOrCreate(['slug' => 'demo'], ['name' => 'Acme Widgets (demo)']);
        $user = User::firstOrCreate(['email' => 'demo@example.com'], ['name' => 'Demo Owner', 'password' => 'demo-password-123']);
        $tenant->users()->syncWithoutDetaching([$user->id => ['role' => User::ROLE_OWNER]]);
        $domain = Domain::firstOrCreate(['name' => 'acme-widgets.example'], ['tenant_id' => $tenant->id]);
        $domain->forceFill([
            'status' => Domain::STATUS_ACTIVE, 'onboarding_step' => 'done', 'current_policy' => 'none', 'current_pct' => 100,
            'dns_checked_at' => now(), 'dns_verified_at' => now()->subDays(31),
        ])->save();

        $sender = fn (string $name) => KnownSender::firstWhere('name', $name)?->id;
        $ips = [
            '209.85.220.41' => ['asn' => 15169, 'org' => 'Google LLC', 'rdns' => 'mail-sor-f41.google.com', 'sender' => $sender('Google Workspace')],
            '209.85.220.69' => ['asn' => 15169, 'org' => 'Google LLC', 'rdns' => 'mail-sor-f69.google.com', 'sender' => $sender('Google Workspace')],
            '198.2.180.12' => ['asn' => 2635, 'org' => 'The Rocket Science Group LLC', 'rdns' => 'mail180-12.suw31.mcsv.net', 'sender' => $sender('Mailchimp')],
            '149.72.154.232' => ['asn' => 11377, 'org' => 'SendGrid, Inc.', 'rdns' => 'o1.ptr2625.sendgrid.net', 'sender' => $sender('SendGrid')],
            '185.220.101.7' => ['asn' => 64511, 'org' => 'Bulletproof Hosting', 'rdns' => null, 'sender' => null],
            '203.0.113.50' => ['asn' => null, 'org' => null, 'rdns' => null, 'sender' => null],
        ];
        foreach ($ips as $ip => $i) {
            IpIntel::updateOrCreate(['ip' => $ip], [
                'asn' => $i['asn'], 'as_org' => $i['org'], 'rdns' => $i['rdns'], 'rdns_verified' => $i['rdns'] !== null,
                'known_sender_id' => $i['sender'], 'match_reason' => $i['sender'] ? 'rdns' : null, 'refreshed_at' => now(),
            ]);
        }

        Report::where('domain_id', $domain->id)->delete();
        $d = $domain->name;
        mt_srand(42);
        for ($back = 29; $back >= 0; $back--) {
            $day = now()->subDays($back)->toDateString();
            $weekday = now()->subDays($back)->isWeekday();
            foreach (['google.com', 'Enterprise Outlook', 'Yahoo'] as $n => $org) {
                $share = [0.6, 0.3, 0.1][$n];
                $report = Report::create([
                    'domain_id' => $domain->id, 'reporter_org' => $org, 'report_id' => "demo-{$day}-{$n}", 'policy_domain' => $d,
                    'date_begin' => $day, 'date_end' => $day.' 23:59:59', 'policy_published' => ['domain' => $d, 'p' => 'none'], 'message_count' => 0,
                ]);
                $rows = [];
                $add = function ($ip, $count, $dkimOk, $spfOk, $dkim, $spf, $reasons = null) use (&$rows, $report, $domain, $day, $d, $share) {
                    $count = (int) round($count * $share * (0.85 + mt_rand(0, 30) / 100));
                    if ($count < 1) {
                        return;
                    }
                    $rows[] = [
                        'report_id' => $report->id, 'domain_id' => $domain->id, 'day' => $day, 'source_ip' => $ip, 'count' => $count,
                        'disposition' => 'none', 'dkim_aligned' => $dkimOk, 'spf_aligned' => $spfOk, 'header_from' => $d,
                        'dkim_results' => json_encode($dkim), 'spf_results' => json_encode($spf), 'reasons' => $reasons ? json_encode($reasons) : null,
                    ];
                };
                $ok = [['domain' => $d, 'selector' => 'google', 'result' => 'pass']];
                $add('209.85.220.41', $weekday ? 1800 : 300, true, true, $ok, [['domain' => $d, 'result' => 'pass']]);
                $add('209.85.220.69', $weekday ? 1200 : 200, true, true, $ok, [['domain' => $d, 'result' => 'pass']]);
                if (in_array(now()->subDays($back)->dayOfWeek, [2, 4], true)) {
                    $add('198.2.180.12', 2500, true, false, [['domain' => $d, 'selector' => 'k1', 'result' => 'pass']], [['domain' => 'mail180.suw31.mcsv.net', 'result' => 'pass']]);
                }
                $add('149.72.154.232', $weekday ? 140 : 20, false, false, [['domain' => 'sendgrid.net', 'selector' => 's1', 'result' => 'pass']], [['domain' => 'em123.sendgrid.net', 'result' => 'pass']]);
                $add('185.220.101.7', mt_rand(0, 60), false, false, [], [['domain' => $d, 'result' => 'fail']]);
                $add('203.0.113.50', 25, false, false, [['domain' => $d, 'selector' => 'google', 'result' => 'fail']], [['domain' => 'lists.partner.example', 'result' => 'pass']], [['type' => 'mailing_list', 'comment' => null]]);
                DB::table('report_records')->insert($rows);
                $report->update(['message_count' => array_sum(array_column($rows, 'count'))]);
            }
            $stats->refreshDay($domain->id, $day);
        }
        $registry->sync($domain->id);
        $domain->update(['first_report_at' => now()->subDays(29), 'last_report_at' => now()]);

        // The customer has recognised their own services; SendGrid is one of them but is misconfigured.
        DomainSource::where('domain_id', $domain->id)->whereIn('label', ['Google Workspace', 'Mailchimp', 'SendGrid'])->update(['classification' => 'legit']);
        DomainSource::where('domain_id', $domain->id)->where('label', 'like', 'AS64511%')->update(['classification' => 'suspicious']);
        app(AlertEvaluator::class)->afterImport($domain->fresh());

        $this->info("Demo ready: sign in at /app as demo@example.com / demo-password-123 (tenant: {$tenant->name}).");

        return self::SUCCESS;
    }
}
