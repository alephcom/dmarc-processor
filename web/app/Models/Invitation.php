<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    public const TTL_DAYS = 7;

    protected $fillable = ['tenant_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    /** @return array{0: Invitation, 1: string} the invitation and the plain token (only ever emailed) */
    public static function issue(Tenant $tenant, string $email, string $role, ?User $by): array
    {
        $token = Str::random(48);
        // One open invitation per address per tenant: re-inviting replaces the old link.
        static::pending()->where('tenant_id', $tenant->id)->where('email', strtolower($email))->delete();
        $inv = static::create([
            'tenant_id' => $tenant->id,
            'email' => strtolower(trim($email)),
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $by?->id,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return [$inv, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return static::with('tenant')->firstWhere('token_hash', hash('sha256', $token));
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
