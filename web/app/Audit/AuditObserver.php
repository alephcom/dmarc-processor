<?php

namespace App\Audit;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Models\Invitation;
use App\Models\KnownSender;
use App\Models\Membership;
use App\Models\NotificationChannel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records user-initiated changes to configuration. System changes (DNS scanner, report import,
 * scheduled jobs) run without a signed-in user and are not recorded.
 */
class AuditObserver
{
    /**
     * model => [audited attributes, label, tenant id resolver]
     * Attributes listed under 'secret' are recorded as "changed" without values.
     */
    public static function config(): array
    {
        return [
            Domain::class => ['fields' => ['name', 'status', 'target_policy', 'dkim_selectors'], 'label' => fn (Domain $m) => "domain {$m->name}", 'tenant' => fn (Domain $m) => $m->tenant_id],
            DomainSource::class => ['fields' => ['classification', 'notes'], 'label' => fn (DomainSource $m) => "sender {$m->label} on ".($m->domain?->name ?? 'domain'), 'tenant' => fn (DomainSource $m) => $m->domain?->tenant_id],
            NotificationChannel::class => ['fields' => ['name', 'enabled', 'min_severity', 'alert_types'], 'secret' => ['config'], 'label' => fn (NotificationChannel $m) => "notification channel {$m->name}", 'tenant' => fn (NotificationChannel $m) => $m->tenant_id],
            AlertRule::class => ['fields' => ['enabled', 'thresholds'], 'label' => fn (AlertRule $m) => "alert setting {$m->type}", 'tenant' => fn (AlertRule $m) => $m->tenant_id],
            Tenant::class => ['fields' => ['name', 'slug', 'retention_days_raw', 'require_mfa', 'digest_frequency'], 'label' => fn (Tenant $m) => "organisation {$m->name}", 'tenant' => fn (Tenant $m) => $m->id],
            KnownSender::class => ['fields' => ['name', 'category', 'asns', 'rdns_suffixes', 'spf_includes', 'dkim_domains', 'docs_url'], 'label' => fn (KnownSender $m) => "known sender {$m->name}", 'tenant' => fn () => null],
            Membership::class => ['fields' => ['role'], 'label' => fn (Membership $m) => ($m->user?->email ?? 'user')."'s membership", 'tenant' => fn (Membership $m) => $m->tenant_id],
            Invitation::class => ['fields' => ['email', 'role'], 'label' => fn (Invitation $m) => "invitation for {$m->email}", 'tenant' => fn (Invitation $m) => $m->tenant_id],
            User::class => ['fields' => ['name', 'email', 'is_super_admin'], 'secret' => ['password', 'app_authentication_secret'], 'label' => fn (User $m) => "user {$m->email}", 'tenant' => fn () => null],
            Alert::class => ['fields' => ['resolution'], 'label' => fn (Alert $m) => "alert \"{$m->title}\"", 'tenant' => fn (Alert $m) => $m->tenant_id],
        ];
    }

    public function created(Model $model): void
    {
        if ($model instanceof Alert || ! $this->userInitiated()) {
            return; // alerts are created by the system
        }
        $cfg = self::config()[$model::class];
        $values = array_intersect_key($model->getAttributes(), array_flip($cfg['fields']));
        Audit::log($this->action($model, 'created'), 'Created '.$cfg['label']($model), $model, $cfg['tenant']($model), array_map(fn ($v) => [null, $this->cast($model, $v)], $values));
    }

    public function updated(Model $model): void
    {
        if (! $this->userInitiated()) {
            return;
        }
        $cfg = self::config()[$model::class];
        $changes = [];
        foreach ($cfg['fields'] as $f) {
            if ($model->wasChanged($f)) {
                $changes[$f] = [$this->cast($model, $model->getOriginal($f)), $this->cast($model, $model->getAttribute($f))];
            }
        }
        foreach ($cfg['secret'] ?? [] as $f) {
            if ($model->wasChanged($f)) {
                $changes[$f] = ['(hidden)', '(changed)'];
            }
        }
        if ($changes === []) {
            return;
        }

        [$action, $description] = match (true) {
            $model instanceof Alert && $model->resolution === 'manual' => ['alert.resolved', 'Marked '.$cfg['label']($model).' resolved'],
            $model instanceof Alert => [null, null],
            $model instanceof User && $model->wasChanged('app_authentication_secret') => ['user.mfa_'.($model->app_authentication_secret ? 'enabled' : 'disabled'),
                ($model->app_authentication_secret ? 'Enabled' : 'Disabled').' two-factor authentication for '.$cfg['label']($model)],
            $model instanceof Membership => ['membership.role_changed', 'Changed role of '.$cfg['label']($model)],
            default => [$this->action($model, 'updated'), 'Updated '.$cfg['label']($model)],
        };
        if ($action !== null) {
            Audit::log($action, $description, $model, $cfg['tenant']($model), $changes);
        }
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Alert || ! $this->userInitiated()) {
            return;
        }
        $cfg = self::config()[$model::class];
        $verb = match (true) {
            $model instanceof Membership => 'Removed',
            $model instanceof Invitation => 'Revoked',
            default => 'Deleted',
        };
        Audit::log($this->action($model, 'deleted'), "{$verb} ".$cfg['label']($model), $model, $cfg['tenant']($model));
    }

    private function userInitiated(): bool
    {
        return auth()->check();
    }

    private function action(Model $model, string $event): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($model))).'.'.$event;
    }

    private function cast(Model $model, mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '[') || is_string($value) && str_starts_with($value, '{')) {
            return json_decode($value, true) ?? $value;
        }

        return $value;
    }
}
