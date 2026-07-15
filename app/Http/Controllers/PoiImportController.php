<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesImportSheetName;
use App\Imports\PoiImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk Excel import for POI (admin / admin_final only, gated by `role`
 * middleware on the route). See App\Imports\PoiImport for the row-level
 * validation / Eloquent-per-row rules.
 */
class PoiImportController extends Controller
{
    use ResolvesImportSheetName;

    public function create(): View
    {
        return view('poi.import');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        // Every row is saved individually through Eloquent (see PoiImport's
        // docblock — deliberate, so PoiObserver keeps dashboard_summary in
        // sync), which is slower than a bulk insert. A file with a few
        // thousand rows can easily take longer than PHP's default 30s
        // max_execution_time; a fatal timeout mid-import isn't catchable
        // with try/catch (it happens at the engine level, after the script
        // has already halted), so it has to be prevented up front instead —
        // same defensive pattern the old v1 import script used.
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        $file = $request->file('file');
        $sheetName = $this->resolveSheetName($file->getRealPath()) ?? 'Data POI';

        $import = new PoiImport($request->user(), $sheetName);

        try {
            Excel::import($import, $file);
        } catch (\Throwable $e) {
            // PoiImport implements SkipsOnError, so a per-row save failure
            // (DB constraint, dropped connection, etc.) never reaches here —
            // it's caught, logged, and counted via $import->errors() below.
            // Only a bootstrap-level failure (most commonly: a multi-sheet
            // file with none of its sheets named "Data POI") gets this far,
            // but log the real exception too rather than guessing.
            Log::error('PoiImportController: Excel::import() gagal total.', [
                'exception' => $e->getMessage(),
            ]);

            return redirect()->route('poi.import.create')->withErrors(
                "Import gagal dibuka: {$e->getMessage()}. Kemungkinan besar karena file punya lebih dari 1 sheet ".
                'dan tidak ada satupun yang bernama persis "Data POI" — rename salah satu tab, atau upload file dengan 1 sheet saja.'
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
            // Technical (non-validation) failures — see PoiImport::onError().
            // Row number isn't available here (Laravel Excel doesn't pass it
            // to onError()), only the error message; already logged in full.
            'technical_errors' => array_map(fn ($e) => $e->getMessage(), $errors),
        ];

        return redirect()->route('poi.import.create')->with('import_summary', $summary);
    }

    public function template(): BinaryFileResponse
    {
        $path = base_path('Template_Import_POI_PROMIS.xlsx');

        abort_unless(file_exists($path), 404);

        return response()->download($path, 'Template_Import_POI_PROMIS.xlsx');
    }
}
