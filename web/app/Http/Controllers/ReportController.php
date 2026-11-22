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
        $this->assertTwoFactorSatisfied($request, $domain->tenant);

        return $this->renderDomain($domain, $request, $builder, shared: false);
    }

    public function tenant(Request $request, Tenant $tenant, ReportBuilder $builder): View
    {
        abort_unless($request->user()->canAccessTenant($tenant), 404);
        $this->assertTwoFactorSatisfied($request, $tenant);

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

    /**
     * Same rules as the panels: super-admins always need two-factor; tenants can require it for
     * their members (single sign-on sessions are exempt, the identity provider did the second factor).
     */
    private function assertTwoFactorSatisfied(Request $request, Tenant $tenant): void
    {
        $user = $request->user();
        $hasTotp = filled($user->getAppAuthenticationSecret());
        abort_if($user->is_super_admin && ! $hasTotp, 403, 'Set up two-factor authentication first.');
        abort_if($tenant->require_mfa && ! $hasTotp && ! $request->session()->has('auth_via_sso'), 403, 'This organisation requires two-factor authentication. Set it up in your profile first.');
    }

    private function renderDomain(Domain $domain, Request $request, ReportBuilder $builder, bool $shared): View
    {
        return view('reports.domain', ['r' => $builder->domain($domain, DomainStats::normalisePeriod($request->query('period'))), 'shared' => $shared]);
    }
}
