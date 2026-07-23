<?php

use App\Http\Controllers\PoiController;
use App\Http\Controllers\PoiImportController;
use Illuminate\Support\Facades\Route;

// NOTE on ordering: static segments (/poi/create, /poi-import) MUST be
// registered before the wildcard /poi/{poi} routes below — Laravel matches
// routes in registration order, so a wildcard route registered first would
// swallow "/poi/create" as $poi = "create" and 404 on the (non-existent)
// model lookup instead of reaching the real create-form route.

// Read: all three roles (admin / admin_final / sales) — scoped server-side
// per-role inside PoiController@index.
Route::get('/poi', [PoiController::class, 'index'])->name('poi.index');

// Write: admin + admin_final only. `sales` is read-only for this module.
Route::middleware('role:admin,admin_final')->group(function () {
    Route::get('/poi/create', [PoiController::class, 'create'])->name('poi.create');
    Route::post('/poi', [PoiController::class, 'store'])->name('poi.store');

    Route::get('/poi-import', [PoiImportController::class, 'create'])->name('poi.import.create');
    Route::post('/poi-import', [PoiImportController::class, 'store'])->name('poi.import.store');
    Route::get('/poi-import/template', [PoiImportController::class, 'template'])->name('poi.import.template');
});

// Read (detail): all three roles — scoped server-side inside PoiController@show.
Route::get('/poi/{poi}', [PoiController::class, 'show'])->name('poi.show');

Route::middleware('role:admin,admin_final')->group(function () {
    Route::get('/poi/{poi}/edit', [PoiController::class, 'edit'])->name('poi.edit');
    Route::put('/poi/{poi}', [PoiController::class, 'update'])->name('poi.update');
    Route::post('/poi/{poi}/hapus', [PoiController::class, 'destroy'])->name('poi.destroy');
    Route::post('/poi/{poi}/reopen', [PoiController::class, 'reopen'])->name('poi.reopen');
});

// Hard delete: admin only (stricter than the soft hapus above) — it takes the
// POI's entire kunjungan history down with it via cascade, which is reporting
// data an admin_final shouldn't be able to erase for their own kantor.
Route::middleware('role:admin')->group(function () {
    Route::delete('/poi/{poi}', [PoiController::class, 'destroyPermanent'])->name('poi.destroy-permanent');
});
