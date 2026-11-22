<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    /** Mirrors the column defaults so new, unsaved-and-unreloaded models behave the same. */
    protected $attributes = ['digest_frequency' => 'weekly', 'retention_days_raw' => 395, 'require_mfa' => false];

    protected $fillable = ['name', 'slug', 'retention_days_raw', 'require_mfa', 'settings', 'digest_frequency', 'last_digest_at'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'require_mfa' => 'boolean', 'last_digest_at' => 'datetime'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    /** Members who manage the tenant: they receive in-app alerts and digests. */
    public function managers(): BelongsToMany
    {
        return $this->users()->wherePivotIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN]);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
}
