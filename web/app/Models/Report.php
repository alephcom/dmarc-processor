<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    protected $fillable = [
        'domain_id', 'inbound_message_id', 'reporter_org', 'reporter_email', 'report_id',
        'policy_domain', 'date_begin', 'date_end', 'policy_published', 'errors', 'message_count',
    ];

    protected function casts(): array
    {
        return [
            'date_begin' => 'datetime',
            'date_end' => 'datetime',
            'policy_published' => 'array',
            'errors' => 'array',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(ReportRecord::class);
    }
}
