<?php

use App\Http\Controllers\KantorController;
use App\Http\Controllers\KantorImportController;
use Illuminate\Support\Facades\Route;

// NOTE on ordering: static segments (/kantor-import, /kantor-import/...) MUST be
// registered before the wildcard /kantor/{kantor} routes below — same reasoning
// as routes/poi.php (Laravel matches routes in registration order).
Route::middleware('role:admin')->group(function () {
    Route::get('/kantor', [KantorController::class, 'index'])->name('kantor.index');
    Route::post('/kantor', [KantorController::class, 'store'])->name('kantor.store');

    Route::get('/kantor-import', [KantorImportController::class, 'create'])->name('kantor.import.create');
    Route::post('/kantor-import', [KantorImportController::class, 'store'])->name('kantor.import.store');

    Route::put('/kantor/{kantor}', [KantorController::class, 'update'])->name('kantor.update');
    Route::post('/kantor/{kantor}/toggle-active', [KantorController::class, 'toggleActive'])->name('kantor.toggle-active');
});
