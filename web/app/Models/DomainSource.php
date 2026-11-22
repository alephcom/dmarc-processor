<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainSource extends Model
{
    protected $fillable = ['domain_id', 'source_key', 'classification', 'notes', 'first_seen_at'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
