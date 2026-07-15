<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesImportSheetName;
use App\Imports\UserImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk Excel import for "Manajemen User" (admin only, gated by `role`
 * middleware on the route). See App\Imports\UserImport for the row-level
 * validation / per-row persistence rules.
 */
class UserImportController extends Controller
{
    use ResolvesImportSheetName;

    public function create(): View
    {
        return view('users.import');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        // Same reasoning as PoiImportController::store() — every row is
        // saved individually through Eloquent (see UserImport's docblock),
        // which can outrun PHP's default 30s max_execution_time on a large
        // file well before Excel::import() below ever gets a chance to
        // finish or throw.
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        $file = $request->file('file');
        $sheetName = $this->resolveSheetName($file->getRealPath()) ?? 'Data User';

        $import = new UserImport($sheetName);

        try {
            Excel::import($import, $file);
        } catch (\Throwable $e) {
            // UserImport implements SkipsOnError, so a per-row save failure
            // never reaches here — it's caught, logged, and counted via
            // $import->errors() below. Only a bootstrap-level failure (most
            // commonly: a multi-sheet file with none of its sheets named
            // "Data User") gets this far.
            Log::error('UserImportController: Excel::import() gagal total.', [
                'exception' => $e->getMessage(),
            ]);

            return redirect()->route('user.import.create')->withErrors(
                "Import gagal dibuka: {$e->getMessage()}. Kemungkinan besar karena file punya lebih dari 1 sheet ".
                'dan tidak ada satupun yang bernama persis "Data User" — rename salah satu tab, atau upload file dengan 1 sheet saja.'
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
            // Technical (non-validation) failures — see UserImport::onError().
            'technical_errors' => array_map(fn ($e) => $e->getMessage(), $errors),
        ];

        return redirect()->route('user.import.create')->with('import_summary', $summary);
    }

    /**
     * response()->download() (not response()->file() or a raw Response with
     * manually-set headers) is what actually produces a BinaryFileResponse
     * with a Content-Disposition: attachment header — the download-as-a-new-
     * file behavior the brief calls out as a known prior bug spot.
     */
    public function template(): BinaryFileResponse
    {
        $path = base_path('Template_Import_User_PROMIS.xlsx');

        abort_unless(file_exists($path), 404);

        return response()->download($path, 'Template_Import_User_PROMIS.xlsx');
    }
}
