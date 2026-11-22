<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A row of tenant_user, as a model so Filament can list and edit team members. */
class Membership extends Model
{
    protected $table = 'tenant_user';

    protected $fillable = ['tenant_id', 'user_id', 'role'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
