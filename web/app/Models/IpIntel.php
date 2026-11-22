<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpIntel extends Model
{
    protected $table = 'ip_intel';

    protected $fillable = ['ip', 'rdns', 'asn', 'as_org', 'country', 'known_sender_id', 'refreshed_at'];

    protected function casts(): array
    {
        return ['refreshed_at' => 'datetime'];
    }

    public function knownSender(): BelongsTo
    {
        return $this->belongsTo(KnownSender::class);
    }
}
