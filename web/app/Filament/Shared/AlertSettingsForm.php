<?php

namespace App\Filament\Shared;

use App\Alerts\AlertSettings;
use App\Alerts\AlertType;
use App\Models\AlertRule;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Alert on/off switches and thresholds, for a tenant (overrides) or the MSP (defaults, tenant NULL).
 */
final class AlertSettingsForm
{
    /** @return list<Section> */
    public static function sections(?int $tenantId): array
    {
        $types = $tenantId ? AlertType::forTenants() : AlertType::cases();
        $sections = [];
        foreach ($types as $type) {
            $defaults = $tenantId ? app(AlertSettings::class)->for(null, $type)['thresholds'] : $type->defaults();
            $fields = [Toggle::make("rules.{$type->value}.enabled")->label('Enabled')->live()];
            foreach ($type->thresholdFields() as $key => $f) {
                $fields[] = TextInput::make("rules.{$type->value}.{$key}")
                    ->label($f['label'])->integer()->required()->minValue($f['min'])->maxValue($f['max'])
                    ->helperText(($tenantId ? 'MSP default: ' : 'Built-in default: ').$defaults[$key])
                    ->visible(fn (Get $get) => (bool) $get("rules.{$type->value}.enabled"));
            }
            $sections[] = Section::make($type->label())->description($type->description())
                ->compact()->columns(3)->schema($fields);
        }

        return $sections;
    }

    /** @return array<string, array<string, mixed>> current values for the form */
    public static function fill(?int $tenantId): array
    {
        $settings = app(AlertSettings::class);
        $out = [];
        foreach ($tenantId ? AlertType::forTenants() : AlertType::cases() as $type) {
            $eff = $settings->for($tenantId, $type);
            $out[$type->value] = ['enabled' => $eff['enabled'], ...$eff['thresholds']];
        }

        return $out;
    }

    /** @param array<string, array<string, mixed>> $rules */
    public static function save(?int $tenantId, array $rules): void
    {
        foreach ($tenantId ? AlertType::forTenants() : AlertType::cases() as $type) {
            $values = $rules[$type->value] ?? [];
            $thresholds = [];
            foreach ($type->thresholdFields() as $key => $f) {
                if (isset($values[$key]) && $values[$key] !== '') {
                    $thresholds[$key] = max($f['min'], min($f['max'], (int) $values[$key]));
                }
            }
            AlertRule::updateOrCreate(
                ['tenant_id' => $tenantId, 'type' => $type->value],
                ['enabled' => (bool) ($values['enabled'] ?? true), 'thresholds' => $thresholds],
            );
        }
        app(AlertSettings::class)->forget();
    }
}
