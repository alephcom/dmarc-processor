<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_id', 'domain_id', 'day', 'source_ip', 'count', 'disposition', 'dkim_aligned', 'spf_aligned',
        'header_from', 'envelope_from', 'envelope_to', 'dkim_results', 'spf_results', 'reasons',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'dkim_aligned' => 'boolean',
            'spf_aligned' => 'boolean',
            'dkim_results' => 'array',
            'spf_results' => 'array',
            'reasons' => 'array',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
