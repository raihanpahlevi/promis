<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\UserImportController;
use Illuminate\Support\Facades\Route;

// No auth/force.password.change/active.kantor group wrapper here on purpose —
// this file is meant to be `require`d inside the existing middleware group in
// routes/web.php (same convention as routes/poi.php and routes/kunjungan.php).
//
// Every route below is admin-only per the finalized PRD: unlike the POI
// module, admin_final has no rights here at all, so the whole file sits
// inside a single `role:admin` group rather than being split per-action.
//
// NOTE on ordering: static segments (/user/create, /user-import) MUST be
// registered before the wildcard /user/{user} routes below — same reasoning
// as routes/poi.php (Laravel matches routes in registration order).
Route::middleware('role:admin')->group(function () {
    Route::get('/user', [UserController::class, 'index'])->name('user.index');
    Route::get('/user/create', [UserController::class, 'create'])->name('user.create');
    Route::post('/user', [UserController::class, 'store'])->name('user.store');

    Route::get('/user-import', [UserImportController::class, 'create'])->name('user.import.create');
    Route::post('/user-import', [UserImportController::class, 'store'])->name('user.import.store');
    Route::get('/user-import/template', [UserImportController::class, 'template'])->name('user.import.template');

    Route::get('/user/{user}/edit', [UserController::class, 'edit'])->name('user.edit');
    Route::put('/user/{user}', [UserController::class, 'update'])->name('user.update');
    Route::post('/user/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('user.toggle-active');
    Route::post('/user/{user}/reset-password', [UserController::class, 'resetPassword'])->name('user.reset-password');
});
