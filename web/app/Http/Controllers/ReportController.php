<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Tenant;
use App\Reports\ReportBuilder;
use App\Services\DomainStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController
{
    public function domain(Request $request, Domain $domain, ReportBuilder $builder): View
    {
        abort_unless($request->user()->canAccessTenant($domain->tenant), 404);

        return $this->renderDomain($domain, $request, $builder, shared: false);
    }

    public function tenant(Request $request, Tenant $tenant, ReportBuilder $builder): View
    {
        abort_unless($request->user()->canAccessTenant($tenant), 404);

        return view('reports.tenant', ['r' => $builder->tenant($tenant, DomainStats::normalisePeriod($request->query('period'))), 'shared' => false]);
    }

    /** Signed link: no account needed. Route middleware checks the signature and expiry. */
    public function sharedDomain(Request $request, Domain $domain, ReportBuilder $builder): View
    {
        abort_unless((int) $request->query('v') === $domain->tenant->report_share_version, 403, 'This link has been revoked.');

        return $this->renderDomain($domain, $request, $builder, shared: true);
    }

    public function sharedTenant(Request $request, Tenant $tenant, ReportBuilder $builder): View
    {
        abort_unless((int) $request->query('v') === $tenant->report_share_version, 403, 'This link has been revoked.');

        return view('reports.tenant', ['r' => $builder->tenant($tenant, DomainStats::normalisePeriod($request->query('period'))), 'shared' => true]);
    }

    private function renderDomain(Domain $domain, Request $request, ReportBuilder $builder, bool $shared): View
    {
        return view('reports.domain', ['r' => $builder->domain($domain, DomainStats::normalisePeriod($request->query('period'))), 'shared' => $shared]);
    }
}
