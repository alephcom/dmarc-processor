<?php

namespace App\Models;

use App\Alerts\AlertManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainSource extends Model
{
    protected $fillable = ['domain_id', 'source_key', 'label', 'classification', 'notes', 'first_seen_at'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Classifying a new sender is the answer to its "new sender" alert.
        static::updated(function (DomainSource $s) {
            if ($s->wasChanged('classification') && $s->classification !== 'unknown') {
                app(AlertManager::class)->resolveKey("new_sender:{$s->domain_id}:{$s->source_key}", 'manual');
            }
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
