<?php

namespace App\Filament\Shared;

use App\Alerts\AlertType;
use App\Alerts\Delivery\AlertMail;
use App\Alerts\Delivery\SafeWebhookTarget;
use App\Alerts\Delivery\WebhookPayload;
use App\Alerts\Severity;
use App\Jobs\SendWebhook;
use App\Models\Alert;
use App\Models\NotificationChannel;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Form and table for notification channels, shared by the tenant panel and the MSP admin panel.
 */
final class ChannelSchema
{
    public static function form(Schema $schema, bool $msp): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->placeholder($msp ? 'NOC mailbox' : 'IT team'),
            Select::make('type')->required()->live()->default(NotificationChannel::EMAIL)
                ->options([NotificationChannel::EMAIL => 'Email', NotificationChannel::WEBHOOK => 'Webhook (JSON POST)'])
                ->disabledOn('edit'),
            TagsInput::make('config.addresses')->label('Email addresses')
                ->visible(fn (Get $get) => $get('type') === NotificationChannel::EMAIL)
                ->required(fn (Get $get) => $get('type') === NotificationChannel::EMAIL)
                ->nestedRecursiveRules(['email:rfc'])
                ->placeholder('Add an address and press Enter')
                ->columnSpanFull(),
            TextInput::make('config.url')->label('Webhook URL')->url()->maxLength(500)
                ->visible(fn (Get $get) => $get('type') === NotificationChannel::WEBHOOK)
                ->required(fn (Get $get) => $get('type') === NotificationChannel::WEBHOOK)
                ->placeholder('https://example.com/hooks/dmarc')
                ->helperText('Must be HTTPS and reachable on the public internet.')
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                    try {
                        app(SafeWebhookTarget::class)->resolve((string) $value);
                    } catch (InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                })
                ->columnSpanFull(),
            TextInput::make('config.secret')->label('Signing secret')
                ->visible(fn (Get $get) => $get('type') === NotificationChannel::WEBHOOK)
                ->default(fn () => Str::random(40))
                ->required(fn (Get $get) => $get('type') === NotificationChannel::WEBHOOK)
                ->minLength(16)->maxLength(200)->password()->revealable()
                ->helperText('Each request carries X-Dmarc-Signature: sha256=HMAC-SHA256(secret, X-Dmarc-Timestamp + "." + body).')
                ->columnSpanFull(),
            Section::make('Which alerts')->columnSpanFull()->schema([
                Select::make('min_severity')->label('Minimum severity')->options(Severity::OPTIONS)->default(Severity::WARNING)->required(),
                CheckboxList::make('alert_types')->label('Alert types')
                    ->options(AlertType::options(includeMsp: $msp))
                    ->helperText('Leave all unticked to receive every type.'
                        .($msp ? ' MSP channels receive alerts for every customer.' : ''))
                    ->columns(2),
                Toggle::make('enabled')->default(true),
            ]),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('medium')->searchable(),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('min_severity')->label('Min. severity')->badge()->color(fn (string $state) => Severity::color($state)),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('last_delivered_at')->label('Last delivered')->since()->placeholder('Never'),
                TextColumn::make('last_error')->label('Last error')->limit(50)->color('danger')->placeholder('—')->wrap(),
            ])
            ->recordActions([self::testAction(), EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No notification channels')
            ->emptyStateDescription('Alerts always appear in the app. Add an email or webhook channel to be told about them elsewhere.');
    }

    public static function testAction(): Action
    {
        return Action::make('test')
            ->label('Send test')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->action(function (NotificationChannel $record) {
                $alert = new Alert([
                    'type' => AlertType::NewSender->value, 'severity' => Severity::INFO,
                    'title' => 'Test alert from '.config('app.name'),
                    'body' => 'This is a test. If you can read it, the "'.$record->name.'" channel works.',
                    'opened_at' => now(), 'last_seen_at' => now(),
                ]);
                $alert->id = 0;
                $alert->setRelation('tenant', $record->tenant);
                $alert->tenant_id = $record->tenant_id;
                try {
                    if ($record->type === NotificationChannel::WEBHOOK) {
                        $payload = WebhookPayload::for($alert, 'test');
                        $payload['event'] = 'test';
                        $error = SendWebhook::send(app(SafeWebhookTarget::class), $record, $payload);
                        $record->refresh();
                        $error ??= $record->last_error;
                    } else {
                        Mail::to($record->config['addresses'] ?? [])->send(new AlertMail($alert));
                        $error = null;
                    }
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
                $error
                    ? Notification::make()->title('Test failed')->body($error)->danger()->send()
                    : Notification::make()->title('Test sent')->success()->send();
            });
    }
}
