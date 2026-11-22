<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailySourceStat extends Model
{
    public $timestamps = false;

    protected $table = 'daily_source_stats';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
