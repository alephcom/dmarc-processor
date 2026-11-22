<?php

namespace App\Models;

use App\Alerts\AlertType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = [
        'tenant_id', 'domain_id', 'type', 'severity', 'dedupe_key', 'title', 'body', 'data',
        'opened_at', 'last_seen_at', 'resolved_at', 'resolution', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function alertType(): AlertType
    {
        return AlertType::from($this->type);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
