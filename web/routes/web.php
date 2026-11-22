<?php

use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

Route::middleware('throttle:20,1')->group(function () {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware('throttle:30,1')->group(function () {
    Route::get('auth/sso/{provider}/redirect', [SsoController::class, 'redirect'])->whereIn('provider', ['google', 'microsoft'])->name('sso.redirect');
    Route::get('auth/sso/{provider}/callback', [SsoController::class, 'callback'])->whereIn('provider', ['google', 'microsoft'])->name('sso.callback');
});

Route::middleware('auth')->group(function () {
    Route::get('reports/domains/{domain}', [ReportController::class, 'domain'])->name('reports.domain');
    Route::get('reports/tenants/{tenant}', [ReportController::class, 'tenant'])->name('reports.tenant');
    Route::post('impersonation/leave', [ImpersonationController::class, 'leave'])->name('impersonation.leave');
});

Route::middleware(['signed', 'throttle:60,1'])->group(function () {
    Route::get('shared/reports/domains/{domain}', [ReportController::class, 'sharedDomain'])->name('reports.shared.domain');
    Route::get('shared/reports/tenants/{tenant}', [ReportController::class, 'sharedTenant'])->name('reports.shared.tenant');
});
