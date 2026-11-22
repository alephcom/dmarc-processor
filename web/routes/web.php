<?php

use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

Route::middleware('throttle:20,1')->group(function () {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware('auth')->group(function () {
    Route::post('impersonation/leave', [ImpersonationController::class, 'leave'])->name('impersonation.leave');
});
