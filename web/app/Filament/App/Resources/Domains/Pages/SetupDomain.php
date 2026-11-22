<?php

namespace App\Filament\App\Resources\Domains\Pages;

use App\Filament\App\Resources\Domains\DomainResource;
use App\Filament\App\Resources\Domains\Setup\SetupContent;
use App\Models\Domain;
use App\Services\DomainScanner;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;

/**
 * Guided setup for a domain: review DNS, publish the DMARC record, verify, wait for reports.
 *
 * @property Domain $record
 */
class SetupDomain extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DomainResource::class;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    private ?SetupContent $setup = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(DomainResource::canEdit($this->record), 403);
        $this->form->fill(['dkim_selectors' => $this->record->dkim_selectors ?? []]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Set up '.$this->record->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Setup';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recheck')
                ->label('Re-check DNS')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->rescan()),
            Action::make('exit')
                ->label('Domain overview')
                ->color('gray')
                ->url(fn () => DomainResource::getUrl('view', ['record' => $this->record])),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('setup')
                ->livewireSubmitHandler('finish'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $keys = Domain::ONBOARDING_STEPS;
        $start = array_search($this->record->onboarding_step, $keys, true);
        $start = $start === false ? (($this->record->onboarding_step === 'done') ? count($keys) - 1 : 0) : $start;

        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make('Overview')
                        ->icon(Heroicon::OutlinedMagnifyingGlass)
                        ->schema(fn () => $this->record->dns_checked_at === null
                            ? [View::make('filament.app.domain-setup.initial-scan')->viewData(['domain' => $this->record->name])]
                            : $this->setup()->overview())
                        ->afterValidation(fn () => $this->advanceTo('spf')),
                    Step::make('SPF')
                        ->icon(Heroicon::OutlinedServerStack)
                        ->schema(fn () => $this->setup()->spf())
                        ->afterValidation(fn () => $this->advanceTo('dkim')),
                    Step::make('DKIM')
                        ->icon(Heroicon::OutlinedKey)
                        ->schema(fn () => [
                            ...$this->setup()->dkim(),
                            TagsInput::make('dkim_selectors')
                                ->label('Other selectors to check')
                                ->placeholder('e.g. mailjet, pm2024')
                                ->nestedRecursiveRules(['regex:/^[A-Za-z0-9._-]{1,63}$/'])
                                ->helperText('Press Enter after each one. We check them when you click Next.'),
                        ])
                        ->afterValidation(fn () => $this->saveSelectors()),
                    Step::make('DMARC')
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->schema(fn () => $this->setup()->dmarc())
                        ->afterValidation(fn () => $this->advanceTo('verify')),
                    Step::make('Verify')
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->schema(fn () => [
                            ...$this->setup()->verify($this->checkNowAction()),
                            ...($this->record->dns_verified_at ? [] : [View::make('filament.app.domain-setup.poll')]),
                        ])
                        ->afterValidation(fn () => $this->advanceTo('waiting')),
                    Step::make('Reports')
                        ->icon(Heroicon::OutlinedInboxArrowDown)
                        ->schema(fn () => $this->setup()->waiting()),
                ])
                    ->startOnStep($start + 1)
                    ->submitAction(new HtmlString(Blade::render('<x-filament::button type="submit">Finish</x-filament::button>'))),
            ]);
    }

    private function setup(): SetupContent
    {
        return $this->setup ??= new SetupContent($this->record, $this->record->latestChecks());
    }

    private function checkNowAction(): Action
    {
        return Action::make('checkNow')
            ->label('Check now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(fn () => $this->verifyNow(notify: true));
    }

    public function initialScan(): void
    {
        if ($this->record->dns_checked_at === null) {
            $this->rescan(notify: false);
        }
    }

    public function pollVerification(): void
    {
        if ($this->record->dns_verified_at === null) {
            $this->verifyNow(notify: false);
        }
    }

    public function rescan(bool $notify = true): void
    {
        if (! $this->throttle('scan', 3)) {
            return;
        }
        app(DomainScanner::class)->scan($this->record);
        $this->refreshState();
        if ($notify) {
            Notification::make()->title('DNS re-checked')->success()->send();
        }
    }

    public function verifyNow(bool $notify): void
    {
        if (! $this->throttle('verify', 6)) {
            return;
        }
        $wasVerified = $this->record->dns_verified_at !== null;
        $result = app(DomainScanner::class)->verify($this->record);
        $this->refreshState();

        if ($result->verified() && ! $wasVerified) {
            Notification::make()->title('Your DMARC record is live')->success()->send();
        } elseif ($notify) {
            Notification::make()->title('Not visible yet')->body('We still can\'t see the updated record. DNS changes can take a while; we\'ll keep checking.')->warning()->send();
        }
    }

    private function saveSelectors(): void
    {
        $selectors = array_values(array_unique(array_map('strtolower', $this->data['dkim_selectors'] ?? [])));
        if ($selectors !== ($this->record->dkim_selectors ?? [])) {
            $this->record->forceFill(['dkim_selectors' => $selectors])->save();
            $this->rescan(notify: false);
        }
        $this->advanceTo('dmarc');
    }

    private function advanceTo(string $step): void
    {
        $keys = Domain::ONBOARDING_STEPS;
        $current = array_search($this->record->onboarding_step, $keys, true);
        if ($this->record->onboarding_step !== 'done' && ($current === false || array_search($step, $keys, true) > $current)) {
            $this->record->forceFill(['onboarding_step' => $step])->save();
        }
    }

    public function finish(): void
    {
        $this->record->forceFill(['onboarding_step' => 'done'])->save();
        Notification::make()->title('Setup complete')->body('We\'ll let you know as soon as reports start arriving.')->success()->send();
        $this->redirect(DomainResource::getUrl('view', ['record' => $this->record]));
    }

    private function refreshState(): void
    {
        $this->record->refresh();
        $this->setup = null;
    }

    /** Max $perMinute DNS checks per domain per minute, shared across users. */
    private function throttle(string $kind, int $perMinute): bool
    {
        $key = "domain-{$kind}:{$this->record->id}";
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return false;
        }
        RateLimiter::hit($key, 60);

        return true;
    }
}
