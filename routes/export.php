<?php

use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:admin,admin_final')->group(function () {
    Route::get('/export/kunjungan/download', [ExportController::class, 'download'])->name('export.kunjungan.download');
    Route::get('/export/poi/download', [ExportController::class, 'downloadPoi'])->name('export.poi.download');
});
