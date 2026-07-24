<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesImportSheetName;
use App\Jobs\ProcessImport;
use App\Models\ImportJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk Excel import for POI (admin / admin_final only, gated by `role`
 * middleware on the route). See App\Imports\PoiImport for the row-level
 * validation / Eloquent-per-row rules.
 *
 * Since 2026-07-24 the actual import runs on the queue (App\Jobs\ProcessImport)
 * instead of inside this request: multi-thousand-row files routinely outlived
 * the shared host's ~60s gateway timeout, so the browser showed 504/"muter"
 * while PHP silently kept importing — which invited double uploads. store()
 * now just parks the file + an ImportJob row and returns immediately; the
 * import page renders the job's live status ("Riwayat Import").
 */
class PoiImportController extends Controller
{
    use ResolvesImportSheetName;

    public function create(): View
    {
        return view('poi.import', [
            'recentJobs' => ImportJob::where('type', ImportJob::TYPE_POI)
                ->latest()->limit(5)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        $file = $request->file('file');
        $sheetName = $this->resolveSheetName($file->getRealPath()) ?? 'Data POI';

        $job = ImportJob::create([
            'type' => ImportJob::TYPE_POI,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $file->store('imports'),
            'sheet_name' => $sheetName,
            'created_by' => $request->user()->id,
        ]);

        ProcessImport::dispatch($job->id);

        return redirect()->route('poi.import.create')->with(
            'status',
            "File {$job->original_filename} diterima — import berjalan di latar belakang. "
            .'Pantau statusnya di Riwayat Import di bawah; halaman ini refresh otomatis.'
        );
    }

    public function template(): BinaryFileResponse
    {
        $path = base_path('Template_Import_POI_PROMIS.xlsx');

        abort_unless(file_exists($path), 404);

        return response()->download($path, 'Template_Import_POI_PROMIS.xlsx');
    }
}
