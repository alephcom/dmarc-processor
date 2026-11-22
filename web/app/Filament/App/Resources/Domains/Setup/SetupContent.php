<?php

namespace App\Filament\App\Resources\Domains\Setup;

use App\Dns\Checks\CheckResult;
use App\Dns\Checks\DmarcRecord;
use App\Dns\Checks\DmarcRecordBuilder;
use App\Dns\Checks\Issue;
use App\Dns\DnsProviders;
use App\Models\Domain;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Support\Enums\FontFamily;

/**
 * Builds the schema components shown in each step of the domain setup wizard,
 * from the most recent stored DNS checks.
 */
class SetupContent
{
    /** SPF include domains of common senders. Moves to the known_senders table with sender identification. */
    private const KNOWN_SPF_INCLUDES = [
        'spf.protection.outlook.com' => 'Microsoft 365',
        '_spf.google.com' => 'Google Workspace',
        'servers.mcsv.net' => 'Mailchimp',
        'spf.mandrillapp.com' => 'Mailchimp Transactional',
        'sendgrid.net' => 'SendGrid',
        'mailgun.org' => 'Mailgun',
        'amazonses.com' => 'Amazon SES',
        'spf.sendinblue.com' => 'Brevo',
        'spf.brevo.com' => 'Brevo',
        '_spf.salesforce.com' => 'Salesforce',
        'mail.zendesk.com' => 'Zendesk',
        'spf.mtasv.net' => 'Postmark',
        '_spf.createsend.com' => 'Campaign Monitor',
        'spf.messagelabs.com' => 'Broadcom Email Security',
        'spf.mimecast.com' => 'Mimecast',
        '_spf.mailjet.com' => 'Mailjet',
        'helpscoutemail.com' => 'Help Scout',
        '_spf.intuit.com' => 'Intuit / QuickBooks',
        'spf.hubspotemail.net' => 'HubSpot',
        '_netblocks.google.com' => 'Google',
        'spfd.protection.outlook.com' => 'Microsoft 365',
    ];

    /** @param array<string, CheckResult> $checks */
    public function __construct(private readonly Domain $domain, private readonly array $checks) {}

    public function check(string $type): CheckResult
    {
        return $this->checks[$type] ?? new CheckResult($type, CheckResult::UNKNOWN);
    }

    public function suggestedRecord(): string
    {
        $dmarc = $this->check('dmarc');
        $existing = $dmarc->raw !== null && ! $dmarc->hasIssue('multiple_records') ? DmarcRecord::parse($dmarc->raw) : null;

        return (new DmarcRecordBuilder)->build($existing, $this->domain->ruaAddress());
    }

    public function dmarcIsLive(): bool
    {
        return $this->domain->dns_verified_at !== null;
    }

    // ---- Step 1: overview ------------------------------------------------------------

    /** @return list<Component> */
    public function overview(): array
    {
        $dmarc = $this->check('dmarc');
        $spf = $this->check('spf');
        $dkim = $this->check('dkim');
        $auth = $this->check('rua_auth');

        $items = [
            Text::make("We looked up the email security records for {$this->domain->name}. Here's where things stand. The next steps walk you through anything that needs fixing."),
            $this->summary('DMARC', $dmarc, match (true) {
                $dmarc->status === CheckResult::UNKNOWN => 'We could not check the DMARC record yet.',
                $dmarc->status === CheckResult::MISSING => 'No DMARC record yet. We\'ll build one for you in step 4.',
                $this->dmarcIsLive() => 'Published, and reports are coming to us (policy: '.($dmarc->parsed['policy'] ?? '?').').',
                isset($dmarc->parsed['policy']) && ! ($dmarc->parsed['has_our_rua'] ?? false) => 'Published (policy: '.$dmarc->parsed['policy'].'), but reports aren\'t sent to us yet. Step 4 shows the updated record.',
                default => 'The record has problems. Step 4 shows a corrected version.',
            }),
            $this->summary('SPF', $spf, match ($spf->status) {
                CheckResult::UNKNOWN => 'We could not check SPF yet.',
                CheckResult::MISSING => 'No SPF record found.',
                CheckResult::OK => 'Valid, using '.($spf->parsed['lookups'] ?? 0).' of 10 DNS lookups.',
                default => 'Published, but has issues to review in step 2.',
            }),
            $this->summary('DKIM', $dkim, match ($dkim->status) {
                CheckResult::UNKNOWN => 'We could not check DKIM yet.',
                CheckResult::MISSING => 'No DKIM keys found at the common selectors. Step 3 helps you find them.',
                default => 'Found keys at: '.implode(', ', array_map(fn ($k) => $k['selector'], array_filter($this->check('dkim')->parsed['keys'] ?? [], fn ($k) => ! $k['revoked']))).'.',
            }),
        ];

        if ($auth->status === CheckResult::ERROR) {
            $items[] = $this->issueCallout($auth->issues[0]);
        }
        if ($this->domain->dns_provider) {
            $items[] = Text::make('DNS for this domain is hosted at '.DnsProviders::instructions($this->domain->dns_provider, '', '')['name'].'. We\'ll show instructions for it.')->color('gray');
        }
        if ($this->domain->dns_checked_at) {
            $items[] = Text::make('Last checked '.$this->domain->dns_checked_at->diffForHumans().'.')->color('gray');
        }

        return $items;
    }

    private function summary(string $label, CheckResult $c, string $text): Callout
    {
        $callout = Callout::make($label.': '.$this->statusLabel($c->status))->description($text);

        return match ($c->status) {
            CheckResult::OK => $callout->success(),
            CheckResult::WARN, CheckResult::MISSING, CheckResult::UNKNOWN => $callout->warning(),
            default => $callout->danger(),
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            CheckResult::OK => 'looks good',
            CheckResult::WARN => 'needs attention',
            CheckResult::MISSING => 'not found',
            CheckResult::UNKNOWN => 'not checked',
            default => 'problem found',
        };
    }

    // ---- Step 2: SPF -------------------------------------------------------------------

    /** @return list<Component> */
    public function spf(): array
    {
        $spf = $this->check('spf');
        $items = [
            Text::make('SPF lists the servers allowed to send email for your domain. DMARC passes if either SPF or DKIM passes and matches your domain, so a healthy SPF record gives you a second route to passing.'),
        ];
        if ($spf->raw !== null) {
            $items[] = TextEntry::make('spf_raw')->label('Current SPF record')->state($spf->raw)->fontFamily(FontFamily::Mono)->copyable();
        }
        if (isset($spf->parsed['lookups'])) {
            $items[] = Grid::make(3)->schema([
                TextEntry::make('spf_lookups')->label('DNS lookups')->state($spf->parsed['lookups'].' of 10')
                    ->color($spf->parsed['lookups'] > 10 ? 'danger' : ($spf->parsed['lookups'] >= 9 ? 'warning' : 'success')),
                TextEntry::make('spf_all')->label('Unlisted servers')->state(match ($spf->parsed['all']) {
                    '-' => 'Fail (-all)', '~' => 'Soft fail (~all)', '?' => 'Neutral (?all)', '+' => 'Pass (+all)', default => 'Not specified',
                }),
                TextEntry::make('spf_voids')->label('Lookups returning nothing')->state((string) ($spf->parsed['void_lookups'] ?? 0)),
            ]);
            $includes = $this->flattenIncludes($spf->parsed['tree']['includes'] ?? []);
            if ($includes !== []) {
                $items[] = Section::make('Services included')->compact()->schema([
                    UnorderedList::make(array_map(fn ($line) => Text::make($line), $includes))->columns(1),
                ]);
            }
        }
        array_push($items, ...$this->issueCallouts($spf));
        if ($spf->status === CheckResult::OK) {
            $items[] = Callout::make('Nothing to change here.')->success();
        } else {
            $items[] = Text::make('Fixing SPF is recommended but not required to continue. You can come back to this later.')->color('gray');
        }

        return $items;
    }

    /** @return list<string> */
    private function flattenIncludes(array $includes, int $depth = 0): array
    {
        $out = [];
        foreach ($includes as $inc) {
            $line = str_repeat('— ', $depth).($inc['kind'] ?? 'include').':'.$inc['domain'];
            if ($name = self::KNOWN_SPF_INCLUDES[$inc['domain']] ?? null) {
                $line .= " ({$name})";
            }
            if (! empty($inc['note'])) {
                $line .= " ({$inc['note']})";
            }
            $out[] = $line;
            array_push($out, ...$this->flattenIncludes($inc['includes'] ?? [], $depth + 1));
        }

        return $out;
    }

    // ---- Step 3: DKIM ------------------------------------------------------------------

    /** @return list<Component> */
    public function dkim(): array
    {
        $dkim = $this->check('dkim');
        $items = [
            Text::make('DKIM adds a cryptographic signature to your email. Each service that sends mail as your domain (Microsoft 365, Google Workspace, your newsletter or CRM tool...) needs its own DKIM key published in your DNS.'),
        ];
        $keys = $dkim->parsed['keys'] ?? [];
        if ($keys !== []) {
            $items[] = Section::make('Keys found')->compact()->schema([
                UnorderedList::make(array_map(fn ($k) => Text::make(
                    $k['selector'].' — '.($k['revoked'] ? 'revoked (empty key)' : strtoupper($k['key_type']).($k['bits'] ? " {$k['bits']}-bit" : ''))
                ), $keys))->columns(1),
            ]);
        }
        array_push($items, ...$this->issueCallouts($dkim, except: ['revoked']));
        $items[] = Text::make('Selectors are the names providers give their DKIM keys. Find yours in each sending service\'s DKIM or domain-authentication settings. Once reports arrive, we also pick up selectors automatically from them.')->color('gray');

        return $items;
    }

    // ---- Step 4: DMARC record ----------------------------------------------------------

    /** @return list<Component> */
    public function dmarc(): array
    {
        $dmarc = $this->check('dmarc');
        $record = $this->suggestedRecord();
        $host = '_dmarc';
        $fqdn = '_dmarc.'.$this->domain->name;

        if ($this->dmarcIsLive()) {
            return [
                Callout::make('Your DMARC record is already set up correctly.')->success()
                    ->description('Reports are being sent to us. Nothing to change here.'),
                TextEntry::make('dmarc_current')->label('Current record')->state($dmarc->raw)->fontFamily(FontFamily::Mono)->copyable(),
            ];
        }

        $items = [];
        if ($dmarc->raw === null) {
            $items[] = Text::make('Publish this TXT record. It starts in monitoring mode (p=none), so no email is blocked: we just start receiving reports about who is sending as your domain.');
        } elseif ($dmarc->hasIssue('multiple_records')) {
            $items[] = Callout::make('You have more than one DMARC record.')->danger()
                ->description("Delete all the existing TXT records at {$fqdn} that start with v=DMARC1, then add the one below.");
        } else {
            $items[] = Text::make('You already have a DMARC record. Replace it with this version: it keeps your existing settings and adds our reporting address.');
            $items[] = TextEntry::make('dmarc_current')->label('Current record')->state($dmarc->raw)->fontFamily(FontFamily::Mono)->color('gray');
        }

        $items[] = Section::make('Record to publish')->schema([
            Grid::make(['default' => 1, 'md' => 4])->schema([
                TextEntry::make('dmarc_type')->label('Type')->state('TXT'),
                TextEntry::make('dmarc_host')->label('Host / Name')->state($host)->fontFamily(FontFamily::Mono)->copyable()
                    ->helperText("Full name: {$fqdn}")->columnSpan(3),
            ]),
            TextEntry::make('dmarc_value')->label('Value')->state($record)->fontFamily(FontFamily::Mono)->copyable(),
        ]);

        $items[] = Section::make('What each part means')->collapsible()->collapsed()->schema([
            ...array_map(fn ($line) => Text::make($line), $this->explainTags(DmarcRecord::parse($record))),
        ]);

        $instructions = DnsProviders::instructions($this->domain->dns_provider, $host, $fqdn);
        $steps = [];
        foreach ($instructions['steps'] as $n => $step) {
            $steps[] = Text::make(($n + 1).'. '.$step);
        }
        $items[] = Section::make('How to add it in '.$instructions['name'])->schema($steps);

        array_push($items, ...$this->issueCallouts($dmarc, except: ['rua_missing', 'missing', 'multiple_records', 'policy_none']));

        return $items;
    }

    /** @return list<string> */
    private function explainTags(DmarcRecord $rec): array
    {
        $ours = $this->domain->ruaAddress();
        $out = [];
        foreach ($rec->tags as $tag => $value) {
            $out[] = "{$tag}={$value}: ".match ($tag) {
                'v' => 'identifies this as a DMARC record.',
                'p' => match (strtolower($value)) {
                    'none' => 'monitor only. Nothing is blocked; you just get reports. The safe place to start.',
                    'quarantine' => 'mail that fails DMARC goes to the recipient\'s spam folder.',
                    'reject' => 'mail that fails DMARC is refused. The goal once all your senders pass.',
                    default => 'the policy for mail that fails DMARC.',
                },
                'sp' => 'the policy for subdomains (defaults to p if not set).',
                'np' => 'the policy for subdomains that don\'t exist (catches made-up subdomains).',
                'pct' => 'the percentage of failing mail the policy applies to, used to roll out gradually.',
                'rua' => 'where daily summary reports are sent. '.(str_contains(strtolower($value), strtolower($ours)) ? "{$ours} is our address." : ''),
                'ruf' => 'where per-message failure reports are sent (rarely used).',
                'adkim' => strtolower($value) === 's' ? 'DKIM must match your domain exactly (strict).' : 'DKIM may use a subdomain of yours (relaxed).',
                'aspf' => strtolower($value) === 's' ? 'SPF must match your domain exactly (strict).' : 'SPF may use a subdomain of yours (relaxed).',
                'fo' => 'when to send failure reports.',
                'ri' => 'requested interval between reports, in seconds.',
                default => 'a setting kept from your existing record.',
            };
        }

        return $out;
    }

    // ---- Step 5: verify ----------------------------------------------------------------

    /** @return list<Component> */
    public function verify(Action $checkNow): array
    {
        $dmarc = $this->check('dmarc');
        $auth = $this->check('rua_auth');
        $items = [];

        if ($this->dmarcIsLive()) {
            $items[] = Callout::make('Your DMARC record is live.')->success()
                ->description('Mailbox providers will start sending reports about '.$this->domain->name.' to us.');
        } else {
            $items[] = Callout::make('We can\'t see the updated record yet.')->warning()
                ->description('DNS changes usually show up within a few minutes but can take up to 48 hours. This page re-checks every 30 seconds while it\'s open, and we keep checking in the background.')
                ->actions([$checkNow]);
            if ($dmarc->raw !== null) {
                $items[] = TextEntry::make('verify_current')->label('What we see now at _dmarc.'.$this->domain->name)->state($dmarc->raw)->fontFamily(FontFamily::Mono);
            }
            if ($dmarc->hasIssue('rua_missing') || $dmarc->status === CheckResult::MISSING) {
                $items[] = Text::make('Our reporting address isn\'t in the published record yet. Go back to the "DMARC" step for the exact record to publish.');
            }
            array_push($items, ...$this->issueCallouts($dmarc, except: ['policy_none', 'rua_missing', 'missing']));
        }

        if ($auth->status === CheckResult::ERROR) {
            $items[] = $this->issueCallout($auth->issues[0]);
        }
        if ($this->domain->dns_checked_at) {
            $items[] = Text::make('Last checked '.$this->domain->dns_checked_at->diffForHumans().'.')->color('gray');
        }

        return $items;
    }

    // ---- Step 6: waiting for data ------------------------------------------------------

    /** @return list<Component> */
    public function waiting(): array
    {
        if ($this->domain->first_report_at) {
            return [
                Callout::make('Reports are arriving.')->success()
                    ->description('The first report arrived '.$this->domain->first_report_at->diffForHumans().'. Your dashboard is filling up.'),
            ];
        }

        $notLive = $this->dmarcIsLive() ? [] : [
            Callout::make('Your DMARC record isn\'t live yet.')->warning()
                ->description('Reports can\'t arrive until it is. You can finish now; we\'ll keep checking in the background and the domain page will show when it\'s live.'),
        ];

        return [
            ...$notLive,
            Text::make(($this->dmarcIsLive() ? 'You\'re all set. ' : '').'Large mailbox providers (Google, Microsoft, Yahoo and others) send one report per day, so the first ones usually arrive within 24–48 hours.'),
            Text::make('This domain shows as "Waiting for reports" until the first report lands, then switches to Active.'),
            Text::make('Keep your policy at p=none for now. Once we have a few weeks of data, we\'ll tell you when it\'s safe to move to quarantine and then reject.'),
            Text::make('If nothing arrives after 3 days, check the record is still published and contact us.'),
        ];
    }

    // ---- shared ------------------------------------------------------------------------

    /**
     * @param  list<string>  $except
     * @return list<Callout>
     */
    private function issueCallouts(CheckResult $c, array $except = []): array
    {
        $out = [];
        foreach ($c->issues as $i) {
            if (! in_array($i->code, $except, true)) {
                $out[] = $this->issueCallout($i);
            }
        }

        return $out;
    }

    private function issueCallout(Issue $i): Callout
    {
        $callout = Callout::make($i->message)->description($i->fix);

        return match ($i->severity) {
            Issue::ERROR => $callout->danger(),
            Issue::WARNING => $callout->warning(),
            default => $callout->info(),
        };
    }
}
