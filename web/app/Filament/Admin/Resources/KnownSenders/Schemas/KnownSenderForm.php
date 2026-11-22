<?php

namespace App\Filament\Admin\Resources\KnownSenders\Schemas;

use App\Models\KnownSender;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class KnownSenderForm
{
    public const CATEGORIES = [
        'mailbox' => 'Mailbox provider', 'esp' => 'Email sending service', 'marketing' => 'Marketing',
        'crm' => 'CRM', 'support' => 'Helpdesk', 'security' => 'Email security gateway', 'other' => 'Other',
    ];

    public static function configure(Schema $schema): Schema
    {
        $domains = fn (string $name, string $label, string $help) => TagsInput::make($name)->label($label)->helperText($help)
            ->nestedRecursiveRules(['regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i']);

        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->unique(KnownSender::class, 'name', ignoreRecord: true),
            Select::make('category')->options(self::CATEGORIES),
            TextInput::make('docs_url')->label('DKIM setup guide URL')->url()->maxLength(255)
                ->helperText('Shown to customers when this sender fails DMARC.')->columnSpanFull(),
            Section::make('How we recognise it')
                ->description('Matching uses reverse DNS (only when it resolves back to the IP), the IP ranges published in the SPF include domains below (live DNS, cached 24 hours), and dedicated networks. Domains match their subdomains too (sendgrid.net matches o1.sendgrid.net). After saving, all source IPs are re-identified in the background.')
                ->columnSpanFull()
                ->schema([
                    $domains('rdns_suffixes', 'Reverse DNS suffixes', 'Only used when the reverse DNS name resolves back to the IP.'),
                    $domains('spf_includes', 'SPF include domains', 'The domains this sender tells customers to include in SPF (e.g. sendgrid.net). We look up their published IP ranges in live DNS; an IP inside them is identified as this sender. Use the provider\'s own include, not a company\'s main domain.'),
                    $domains('dkim_domains', 'DKIM signing domains', 'Reference only. Not used for matching (forgeable in reports, and signatures survive forwarding).'),
                    TagsInput::make('asns')->label('Dedicated ASNs')
                        ->helperText('Only networks used exclusively by this sender. Never add shared clouds (AWS, Azure, Google Cloud).')
                        ->nestedRecursiveRules(['integer', 'min:1', 'max:4294967295']),
                ]),
        ])->columns(2);
    }
}
