<?php

use App\Http\Controllers\KunjunganController;
use Illuminate\Support\Facades\Route;

// No auth/force.password.change/active.kantor group wrapper here on purpose — this
// file is meant to be `require`d inside the existing middleware group in
// routes/web.php. Role gating below is specific to each action within this module,
// so it's applied per-route rather than left to the shared group.

// sales + admin_final log visits (confirmed against the real v1 system — admin_final
// picks one specific kantor first, sales is locked to their session active kantor).
// admin also has access (opened up on request, for checking/testing the flow) — picks
// from every kantor rather than an owned subset, same pattern as admin_final.
Route::get('/kunjungan/create', [KunjunganController::class, 'create'])
    ->name('kunjungan.create')
    ->middleware('role:sales,admin_final,admin');

Route::post('/kunjungan', [KunjunganController::class, 'store'])
    ->name('kunjungan.store')
    ->middleware('role:sales,admin_final,admin');

Route::get('/kunjungan/riwayat', [KunjunganController::class, 'riwayat'])
    ->name('kunjungan.riwayat')
    ->middleware('role:sales');

Route::get('/kunjungan', [KunjunganController::class, 'index'])
    ->name('kunjungan.index')
    ->middleware('role:admin,admin_final');

Route::post('/kunjungan/{kunjungan}/reopen', [KunjunganController::class, 'reopen'])
    ->name('kunjungan.reopen')
    ->middleware('role:admin,admin_final');
