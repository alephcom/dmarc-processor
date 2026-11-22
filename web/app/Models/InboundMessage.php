<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';         // no report attachment found

    public const STATUS_QUARANTINED = 'quarantined'; // report for a domain the token doesn't cover

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'spool_id', 'domain_id', 'rua_token', 'remote_ip', 'mail_from', 'subject',
        'raw_path', 'size', 'status', 'error', 'reports_count', 'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
