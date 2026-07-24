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
 * Bulk Excel import for "Manajemen User" (admin only, gated by `role`
 * middleware on the route). See App\Imports\UserImport for the row-level
 * validation / per-row persistence rules.
 *
 * Since 2026-07-24 the actual import runs on the queue — see
 * PoiImportController's docblock for the full why (same 504-on-shared-hosting
 * story; user files are even worse per row because every password is
 * bcrypt-hashed).
 */
class UserImportController extends Controller
{
    use ResolvesImportSheetName;

    public function create(): View
    {
        return view('users.import', [
            'recentJobs' => ImportJob::where('type', ImportJob::TYPE_USER)
                ->latest()->limit(5)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        $file = $request->file('file');
        $sheetName = $this->resolveSheetName($file->getRealPath()) ?? 'Data User';

        $job = ImportJob::create([
            'type' => ImportJob::TYPE_USER,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $file->store('imports'),
            'sheet_name' => $sheetName,
            'created_by' => $request->user()->id,
        ]);

        ProcessImport::dispatch($job->id);

        return redirect()->route('user.import.create')->with(
            'status',
            "File {$job->original_filename} diterima — import berjalan di latar belakang. "
            .'Pantau statusnya di Riwayat Import di bawah; halaman ini refresh otomatis.'
        );
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
