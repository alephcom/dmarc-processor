<?php

namespace App\Console\Commands;

use App\Digests\DigestBuilder;
use App\Digests\MspDigestMail;
use App\Digests\TenantDigestMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Runs daily. Weekly digests go out on Mondays, monthly ones on the 1st; --force sends now.
 */
class SendDigests extends Command
{
    protected $signature = 'dmarc:send-digests {--force : Send to every tenant now, ignoring frequency and day} {--tenant= : Only this tenant slug}';

    protected $description = 'Email summary digests to tenant owners/admins and to MSP super-admins';

    public function handle(DigestBuilder $builder): int
    {
        $today = now();
        $force = (bool) $this->option('force');
        $sent = 0;

        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->get();
        foreach ($tenants as $tenant) {
            $days = match ($tenant->digest_frequency) {
                'weekly' => ($force || $today->isMonday()) ? 7 : null,
                'monthly' => ($force || $today->day === 1) ? 30 : null,
                default => $force ? 7 : null,
            };
            // Guard against double sends if the scheduler runs twice.
            if ($days === null || (! $force && $tenant->last_digest_at?->gt($today->copy()->subHours(20)))) {
                continue;
            }
            $recipients = $tenant->managers()->pluck('email')->all();
            if ($recipients === []) {
                continue;
            }
            Mail::to($recipients)->send(new TenantDigestMail($builder->forTenant($tenant, $days)));
            $tenant->forceFill(['last_digest_at' => $today])->save();
            $sent++;
        }

        if (! $this->option('tenant') && ($force || $today->isMonday())) {
            $admins = User::where('is_super_admin', true)->pluck('email')->all();
            if ($admins !== []) {
                Mail::to($admins)->send(new MspDigestMail($builder->forMsp(7)));
                $sent++;
            }
        }

        $this->info("Sent {$sent} digest(s).");

        return self::SUCCESS;
    }
}
