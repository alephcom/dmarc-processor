<?php

namespace App\Alerts;

use App\Models\AlertRule;

/**
 * Effective alert settings: tenant override → MSP default (tenant_id NULL) → built-in default.
 */
class AlertSettings
{
    /** @var array<string, AlertRule|null> */
    private array $cache = [];

    /** @return array{enabled: bool, thresholds: array<string, int|float>} */
    public function for(?int $tenantId, AlertType $type): array
    {
        $tenant = $tenantId ? $this->rule($tenantId, $type) : null;
        $msp = $this->rule(null, $type);

        return [
            'enabled' => $tenant?->enabled ?? $msp?->enabled ?? true,
            'thresholds' => [...$type->defaults(), ...($msp?->thresholds ?? []), ...($tenant?->thresholds ?? [])],
        ];
    }

    public function enabled(?int $tenantId, AlertType $type): bool
    {
        return $this->for($tenantId, $type)['enabled'];
    }

    public function threshold(?int $tenantId, AlertType $type, string $key): int|float
    {
        return $this->for($tenantId, $type)['thresholds'][$key];
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function rule(?int $tenantId, AlertType $type): ?AlertRule
    {
        $key = ($tenantId ?? 'msp').':'.$type->value;

        return $this->cache[$key] ??= AlertRule::where('tenant_id', $tenantId)->where('type', $type->value)->first();
    }
}
