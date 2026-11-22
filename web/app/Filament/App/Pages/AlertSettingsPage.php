<?php

namespace App\Filament\App\Pages;

use App\Filament\Shared\AlertSettingsForm;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AlertSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Alerts & summaries';

    protected static ?string $title = 'Alerts & summaries';

    protected static ?string $slug = 'alert-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant && (auth()->user()?->canManage($tenant) ?? false);
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        $this->form->fill(['digest_frequency' => $tenant->digest_frequency, 'rules' => AlertSettingsForm::fill($tenant->id)]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('settings')->livewireSubmitHandler('save')
                ->footer([Action::make('save')->label('Save')->submit('save')]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Summary email')
                ->description('A summary of every domain, sent to owners and admins of this organisation.')
                ->compact()
                ->schema([
                    Select::make('digest_frequency')->label('Send')->required()
                        ->options(['weekly' => 'Weekly (Mondays)', 'monthly' => 'Monthly (1st of the month)', 'off' => 'Never']),
                ]),
            ...AlertSettingsForm::sections(Filament::getTenant()->id),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $tenant = Filament::getTenant();
        $tenant->update(['digest_frequency' => $data['digest_frequency']]);
        AlertSettingsForm::save($tenant->id, $data['rules'] ?? []);
        Notification::make()->title('Saved')->success()->send();
    }
}
