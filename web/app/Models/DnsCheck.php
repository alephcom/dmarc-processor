<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['domain_id', 'type', 'raw', 'parsed', 'issues', 'status', 'checked_at'];

    protected function casts(): array
    {
        return ['parsed' => 'array', 'issues' => 'array', 'checked_at' => 'datetime'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
