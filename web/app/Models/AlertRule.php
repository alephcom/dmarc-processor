<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    protected $fillable = ['tenant_id', 'type', 'enabled', 'thresholds'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'thresholds' => 'array'];
    }
}
