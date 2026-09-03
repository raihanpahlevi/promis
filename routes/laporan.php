<?php

use App\Http\Controllers\LaporanController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:admin,admin_final')->group(function () {
    Route::get('/laporan/rekap-sales', [LaporanController::class, 'rekapSales'])->name('laporan.rekap-sales');
    Route::get('/laporan/summary-kunjungan', [LaporanController::class, 'summaryKunjungan'])->name('laporan.summary-kunjungan');
    Route::get('/laporan/summary-produk', [LaporanController::class, 'summaryProduk'])->name('laporan.summary-produk');

    // Export sits behind the same role gate as the report itself — it carries
    // exactly the rows that report just showed, nothing wider.
    Route::get('/laporan/summary-kunjungan/export', [LaporanController::class, 'exportSummaryKunjungan'])->name('laporan.summary-kunjungan.export');
});
