<?php

use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:admin,admin_final')->group(function () {
    Route::get('/export/kunjungan/download', [ExportController::class, 'download'])->name('export.kunjungan.download');
    Route::get('/export/poi/download', [ExportController::class, 'downloadPoi'])->name('export.poi.download');
});

// Kantor export is admin-only (unlike the two above) — matches Kelola Kantor
// itself (routes/kantor.php), since renaming/re-coding kantor is an
// admin-level action, admin_final has no reason to round-trip this file.
Route::middleware('role:admin')->group(function () {
    Route::get('/export/kantor/download', [ExportController::class, 'downloadKantor'])->name('export.kantor.download');
});
