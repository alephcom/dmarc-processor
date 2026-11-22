<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'tenant_id', 'name', 'rua_token', 'status', 'onboarding_step',
        'current_policy', 'current_pct', 'target_policy', 'dkim_selectors',
        'first_report_at', 'last_report_at',
    ];

    protected function casts(): array
    {
        return [
            'dkim_selectors' => 'array',
            'first_report_at' => 'datetime',
            'last_report_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Domain $d) {
            $d->name = strtolower(rtrim(trim($d->name), '.'));
            $d->rua_token ??= static::generateToken();
        });
    }

    /** 10 chars of lowercase RFC 4648 base32 (a-z, 2-7), ~50 bits of entropy. */
    public static function generateToken(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        do {
            $t = '';
            for ($i = 0; $i < 10; $i++) {
                $t .= $alphabet[random_int(0, 31)];
            }
        } while (static::where('rua_token', $t)->exists());

        return $t;
    }

    public function ruaAddress(): string
    {
        return 'rua-'.$this->rua_token.'@'.config('dmarc.reports_domain');
    }

    /** Whether a report for $policyDomain may be filed under this domain (itself or a subdomain). */
    public function covers(string $policyDomain): bool
    {
        $policyDomain = strtolower(rtrim($policyDomain, '.'));

        return $policyDomain === $this->name || str_ends_with($policyDomain, '.'.$this->name);
    }

    public function acceptsReports(): bool
    {
        return $this->status !== self::STATUS_PAUSED;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(DailyDomainStat::class);
    }

    public function sourceStats(): HasMany
    {
        return $this->hasMany(DailySourceStat::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(DomainSource::class);
    }

    public function dnsChecks(): HasMany
    {
        return $this->hasMany(DnsCheck::class);
    }
}
