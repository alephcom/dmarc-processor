<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use SensitiveParameter;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_VIEWER = 'viewer';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withPivot('role')->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => (bool) $this->is_super_admin,
            'app' => (bool) $this->is_super_admin || $this->tenants()->exists(),
            default => false,
        };
    }

    public function getTenants(Panel $panel): array|Collection
    {
        // Super-admins can open any tenant's workspace (support/impersonation-lite); access is audited later.
        return $this->is_super_admin ? Tenant::orderBy('name')->get() : $this->tenants()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->is_super_admin || $this->tenants()->whereKey($tenant->getKey())->exists();
    }

    public function roleIn(Tenant $tenant): ?string
    {
        if ($this->is_super_admin) {
            return self::ROLE_OWNER;
        }

        return $this->tenants()->whereKey($tenant->getKey())->first()?->pivot->role;
    }

    public function canManage(Tenant $tenant): bool
    {
        return in_array($this->roleIn($tenant), [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }
}
