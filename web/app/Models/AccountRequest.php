<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request for a free hosted account, submitted from the public site. */
class AccountRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING => 'Pending', self::APPROVED => 'Approved', self::REJECTED => 'Rejected'];

    protected $fillable = ['name', 'email', 'organisation', 'domains', 'message', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
