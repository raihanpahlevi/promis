<?php

use App\Http\Controllers\HistogramController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:admin,admin_final')->group(function () {
    Route::get('/histogram', [HistogramController::class, 'index'])->name('histogram.index');
});
