<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\AlertSettingsForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DefaultAlertSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Default alert settings';

    protected static ?string $title = 'Default alert settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['rules' => AlertSettingsForm::fill(null)]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('These apply to every customer unless they change them in their own Alerts & summaries settings.'),
            Form::make([EmbeddedSchema::make('form')])->id('settings')->livewireSubmitHandler('save')
                ->footer([Action::make('save')->label('Save')->submit('save')]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(AlertSettingsForm::sections(null));
    }

    public function save(): void
    {
        AlertSettingsForm::save(null, $this->form->getState()['rules'] ?? []);
        Notification::make()->title('Saved')->success()->send();
    }
}
