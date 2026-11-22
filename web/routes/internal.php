<?php

use App\Http\Controllers\Internal\IngestController;
use App\Http\Middleware\VerifyIngestSignature;
use Illuminate\Support\Facades\Route;

// Called only by smtp-receiver over the internal Docker network. Caddy blocks /internal/* publicly.
Route::prefix('internal')->middleware(VerifyIngestSignature::class)->group(function () {
    Route::get('recipients/{token}', [IngestController::class, 'recipient'])->where('token', '[A-Za-z2-7]{10}');
    Route::post('ingest', [IngestController::class, 'ingest']);
});
