<?php

namespace App\Http\Controllers;

use App\Imports\KantorImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bulk Excel import for Kantor (admin-only, gated by `role` middleware on
 * the route). See App\Imports\KantorImport for the row-level validation /
 * Eloquent-per-row rules. Single-sheet only — unlike PoiImport's official
 * template, there's no multi-sheet "Petunjuk"/"_lookup" layout to guard
 * against here, so no ResolvesImportSheetName/WithMultipleSheets needed.
 */
class KantorImportController extends Controller
{
    public function create(): View
    {
        return view('kantor.import');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        $import = new KantorImport();

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Throwable $e) {
            Log::error('KantorImportController: Excel::import() gagal total.', [
                'exception' => $e->getMessage(),
            ]);

            return redirect()->route('kantor.import.create')->withErrors(
                "Import gagal dibuka: {$e->getMessage()}."
            );
        }

        $failures = $import->failures();
        $errors = $import->errors();

        $summary = [
            'imported' => $import->importedCount(),
            'rejected' => $failures->count(),
            'errors' => $failures->map(fn ($failure) => [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
            ])->all(),
            'technical_errors' => array_map(fn ($e) => $e->getMessage(), $errors),
        ];

        return redirect()->route('kantor.import.create')->with('import_summary', $summary);
    }
}
