<?php

namespace App\Models;

use App\Alerts\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannel extends Model
{
    public const EMAIL = 'email';

    public const WEBHOOK = 'webhook';

    protected $fillable = ['tenant_id', 'name', 'type', 'config', 'alert_types', 'min_severity', 'enabled', 'last_delivered_at', 'last_error'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array', // webhook URLs and secrets are credentials
            'alert_types' => 'array',
            'enabled' => 'boolean',
            'last_delivered_at' => 'datetime',
        ];
    }

    public function wants(Alert $alert): bool
    {
        return $this->enabled
            && Severity::atLeast($alert->severity, $this->min_severity)
            && ($this->alert_types === null || $this->alert_types === [] || in_array($alert->type, $this->alert_types, true));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
