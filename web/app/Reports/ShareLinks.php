<?php

namespace App\Reports;

use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Support\Facades\URL;

/**
 * Signed, expiring report links for people without an account (e.g. a customer's director).
 * Each link carries the tenant's share version; bumping it revokes every link at once.
 */
final class ShareLinks
{
    public const TTL_DAYS = 14;

    public static function domain(Domain $domain, int $days): string
    {
        return URL::temporarySignedRoute('reports.shared.domain', now()->addDays(self::TTL_DAYS),
            ['domain' => $domain->id, 'period' => $days, 'v' => $domain->tenant->report_share_version]);
    }

    public static function tenant(Tenant $tenant, int $days): string
    {
        return URL::temporarySignedRoute('reports.shared.tenant', now()->addDays(self::TTL_DAYS),
            ['tenant' => $tenant->id, 'period' => $days, 'v' => $tenant->report_share_version]);
    }

    public static function revokeAll(Tenant $tenant): void
    {
        $tenant->forceFill(['report_share_version' => $tenant->report_share_version + 1])->save();
    }
}
