<?php

use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PilihKantorController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Must stay reachable even mid force-password-change / before an active kantor is picked.
    Route::get('/ganti-password', [ChangePasswordController::class, 'edit'])->name('ganti-password');
    Route::post('/ganti-password', [ChangePasswordController::class, 'update']);

    Route::middleware('force.password.change')->group(function () {
        Route::get('/pilih-kantor', [PilihKantorController::class, 'edit'])->name('pilih-kantor');
        Route::post('/pilih-kantor', [PilihKantorController::class, 'update']);

        Route::middleware('active.kantor')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

            require __DIR__.'/poi.php';
            require __DIR__.'/kunjungan.php';
            require __DIR__.'/user.php';
            require __DIR__.'/export.php';
            require __DIR__.'/histogram.php';
            require __DIR__.'/unit.php';
            require __DIR__.'/laporan.php';
        });
    });
});
