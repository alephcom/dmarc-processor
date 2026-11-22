<?php

namespace App\Console\Commands;

use App\AccountRequests\Turnstile;
use App\Dns\DnsLookupException;
use App\Dns\Resolver;
use App\Intel\AsnDatabase;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Str;
use Throwable;

/**
 * First-run checklist: configuration, database, DNS for the reports domain, IP database,
 * and the first super-admin. Safe to re-run at any time; --check makes no changes.
 */
class Install extends Command
{
    protected $signature = 'dmarc:install {--check : Only run the checks; no prompts, non-zero exit on failure}';

    protected $description = 'Check the installation and walk through first-run setup';

    /** @var list<array{0: string, 1: string, 2: string, 3: ?string}> [status, check, detail, fix] */
    private array $results = [];

    public function handle(Resolver $dns, AsnDatabase $asn, Migrator $migrator): int
    {
        $reports = strtolower((string) config('dmarc.reports_domain'));
        $mx = strtolower((string) config('dmarc.mx_hostname'));
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        $this->line('');
        $this->line('<options=bold>'.config('app.name').' installation check</>');
        $this->line('');

        // Configuration
        $this->check(filled(config('app.key')), 'App key', filled(config('app.key')) ? 'Set' : 'Missing', 'Run: php artisan key:generate --show and put the value in APP_KEY');
        $this->check(str_starts_with((string) config('app.url'), 'https://') && ! in_array($appHost, ['localhost', '127.0.0.1'], true),
            'App URL', (string) config('app.url'), 'Set APP_URL to the public https:// address of the web UI', warnOnly: ! app()->isProduction());
        $secretOk = strlen((string) config('dmarc.ingest_hmac_secret')) >= 32;
        $this->check($secretOk, 'Ingest secret', $secretOk ? 'Set' : 'Missing or shorter than 32 characters', 'Generate one with: openssl rand -hex 32 (same value for the smtp-receiver)');
        $this->check($reports !== '' && ! str_ends_with($reports, 'localhost'), 'Reports domain', $reports ?: '(not set)', 'Set REPORTS_DOMAIN, e.g. reports.example-msp.com');
        $this->check($mx !== '' && ! str_ends_with($mx, 'localhost'), 'MX hostname', $mx ?: '(not set)', 'Set MX_HOSTNAME, e.g. mx.reports.example-msp.com');
        $this->check(config('queue.default') !== 'sync', 'Queue', (string) config('queue.default'), 'Use QUEUE_CONNECTION=redis so report processing runs in the background', warnOnly: true);
        $this->check(! in_array(config('mail.default'), ['log', 'array'], true), 'Outbound mail', (string) config('mail.default'), 'Configure MAIL_* so alerts, invitations and summaries are delivered', warnOnly: true);
        if (config('dmarc.public_site.enabled')) {
            $this->check(Turnstile::enabled(), 'Spam protection', Turnstile::enabled() ? 'Cloudflare Turnstile' : 'Turnstile not configured',
                'Set TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY to protect the public account-request form', warnOnly: true);
        }

        // Database
        try {
            $migrator->setConnection(config('database.default'));
            $pending = $migrator->repositoryExists()
                ? array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan())
                : ['all'];
            $this->check($pending === [], 'Database', $pending === [] ? 'Connected, migrations up to date' : count($pending).' migration(s) pending', 'Run: php artisan migrate --force');
        } catch (Throwable $e) {
            $this->check(false, 'Database', 'Cannot connect: '.Str::limit($e->getMessage(), 80), 'Check DB_* settings and that MariaDB is running');
        }

        // DNS
        if ($reports !== '' && ! str_ends_with($reports, 'localhost')) {
            try {
                $mxRecords = $dns->mx($reports);
                $this->check(in_array($mx, $mxRecords, true), 'MX record', $mxRecords ? "{$reports} → ".implode(', ', $mxRecords) : "no MX for {$reports}",
                    "Publish: {$reports}. MX 10 {$mx}.");
                $this->check($dns->hasAddress($mx), 'MX host address', $mx, "Publish an A (and AAAA) record for {$mx} pointing at this server");
                $probe = 'install-check-'.Str::lower(Str::random(6)).'.example._report._dmarc.'.$reports;
                $auth = collect($dns->txt($probe))->contains(fn ($t) => (bool) preg_match('/^\s*v=DMARC1/i', $t));
                $this->check($auth, 'Report authorisation', '*._report._dmarc.'.$reports,
                    "Publish: *._report._dmarc.{$reports}. TXT \"v=DMARC1\" (lets mailbox providers send reports for every customer domain)");
                if ($appHost !== '' && ! in_array($appHost, ['localhost', '127.0.0.1'], true)) {
                    $this->check($dns->hasAddress($appHost), 'Web UI address', $appHost, "Publish an A (and AAAA) record for {$appHost} pointing at this server");
                }
            } catch (DnsLookupException $e) {
                $this->check(false, 'DNS', $e->getMessage(), 'Check this server can resolve public DNS', warnOnly: true);
            }
        }

        // IP database
        $this->check($asn->available(), 'IP database', $asn->available() ? 'Installed' : 'Not downloaded yet',
            'Run: php artisan dmarc:update-ip-db (the scheduler also retries hourly)', warnOnly: true);

        // First admin
        try {
            $admins = User::query()->where('is_super_admin', true)->count();
            $this->check($admins > 0, 'MSP admin', $admins ? "{$admins} super-admin(s)" : 'none yet', 'Run: php artisan dmarc:make-super-admin you@example-msp.com');
        } catch (Throwable) {
            $admins = null; // database not ready; already reported above
        }

        $this->table(['', 'Check', 'Detail'], array_map(fn ($r) => [$r[0], $r[1], $r[2]], $this->results));
        $failed = array_filter($this->results, fn ($r) => $r[0] !== '✓');
        foreach ($failed as $r) {
            if ($r[3]) {
                $this->line(($r[0] === '✗' ? '<fg=red>' : '<fg=yellow>')."{$r[1]}:</> {$r[3]}");
            }
        }

        if (! $this->option('check') && $admins === 0 && $this->input->isInteractive() && $this->confirm('Create the first MSP super-admin now?', true)) {
            $this->call('dmarc:make-super-admin');
        }

        $this->line('');
        $this->line("DNS to publish for the reports domain (in your MSP's DNS zone):");
        $this->line("  {$reports}.                    MX   10 {$mx}.");
        $this->line("  {$mx}.                         A    <this server's IPv4>");
        $this->line("  *._report._dmarc.{$reports}.   TXT  \"v=DMARC1\"");
        if ($appHost) {
            $this->line("  {$appHost}.                    A    <this server's IPv4>");
        }
        $this->line('');

        $hardFails = array_filter($this->results, fn ($r) => $r[0] === '✗');
        if ($hardFails === []) {
            $this->info('All required checks passed.');

            return self::SUCCESS;
        }
        $this->error(count($hardFails).' required check(s) failed.');

        return self::FAILURE;
    }

    private function check(bool $ok, string $name, string $detail, ?string $fix, bool $warnOnly = false): void
    {
        $this->results[] = [$ok ? '✓' : ($warnOnly ? '!' : '✗'), $name, $detail, $ok ? null : $fix];
    }
}
