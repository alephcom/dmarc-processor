<?php

namespace App\Filament\App\Resources\Domains\Pages;

use App\Filament\App\Resources\Domains\DomainResource;
use App\Filament\App\Resources\Domains\Schemas\DomainForm;
use App\Jobs\ScanDomainDns;
use App\Models\Domain;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\UniqueConstraintViolationException;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    public const BULK_LIMIT = 100;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkAdd')
                ->label('Add several')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->visible(fn () => DomainResource::canCreate())
                ->modalHeading('Add several domains')
                ->modalDescription('Each domain is added and its DNS checked in the background. Open a domain afterwards to publish its DMARC record.')
                ->modalSubmitActionLabel('Add domains')
                ->schema([
                    Textarea::make('domains')
                        ->label('Domains')
                        ->placeholder("example.com\nexample.org")
                        ->helperText('One per line, or separated by commas or spaces. Up to '.self::BULK_LIMIT.' at a time. You can paste a column from a spreadsheet.')
                        ->rows(10)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->bulkAdd((string) $data['domains'])),
            CreateAction::make(),
        ];
    }

    /** @return array{added: list<string>, existing: list<string>, invalid: list<string>} */
    protected function bulkAdd(string $input): array
    {
        abort_unless(DomainResource::canCreate(), 403);

        $names = array_values(array_unique(array_filter(array_map(
            fn (string $n) => DomainForm::normalise(trim($n, " \t\"'")),
            preg_split('/[\s,;]+/', $input) ?: [],
        ))));
        $result = ['added' => [], 'existing' => [], 'invalid' => []];

        if (count($names) > self::BULK_LIMIT) {
            Notification::make()->title('Too many domains')->body('Add up to '.self::BULK_LIMIT.' at a time.')->danger()->send();

            return $result;
        }

        $tenant = Filament::getTenant();
        foreach ($names as $name) {
            if (! preg_match(DomainForm::DOMAIN_REGEX, $name)) {
                $result['invalid'][] = $name;

                continue;
            }
            try {
                $domain = Domain::create(['tenant_id' => $tenant->getKey(), 'name' => $name]);
            } catch (UniqueConstraintViolationException) {
                // Names are unique across all tenants (the tenant scope hides others' domains from
                // a lookup, so rely on the index). Don't reveal which tenant has it.
                $result['existing'][] = $name;

                continue;
            }
            ScanDomainDns::dispatch($domain->id);
            $result['added'][] = $name;
        }

        $lines = array_filter([
            $result['added'] ? count($result['added']).' added.' : null,
            $result['existing'] ? 'Already monitored: '.implode(', ', $result['existing']).'.' : null,
            $result['invalid'] ? 'Not valid domain names: '.implode(', ', array_map(fn ($n) => mb_strimwidth($n, 0, 60, '…'), $result['invalid'])).'.' : null,
        ]);
        Notification::make()
            ->title($result['added'] ? 'Domains added' : 'No domains added')
            ->body(implode(' ', $lines))
            ->color($result['existing'] || $result['invalid'] ? 'warning' : 'success')
            ->icon($result['added'] ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
            ->persistent()
            ->send();

        return $result;
    }
}
