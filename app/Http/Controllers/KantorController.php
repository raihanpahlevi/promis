<?php

namespace App\Http\Controllers;

use App\Models\Kantor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Kelola Cabang" (admin-only) — a Kantor ("Cabang") row was previously only
 * ever created implicitly by PoiImport::resolveOrCreateKantorId() when an
 * admin's import mentioned an unrecognized Cabang name; there was no direct
 * way to rename/re-code one, or to bulk-edit many at once, without that
 * import path silently creating a duplicate instead (see KantorExport's
 * docblock for the incident that motivated this, 2026-07-22). This gives
 * both a quick single-row form (mirrors UnitController) and a bulk Excel
 * round-trip (KantorImportController) for editing many at once — including
 * bulk-setting Area/Cabang-Cluster (2026-07-23), the hierarchy a POI's own
 * Area/Cabang-Cluster display is always read through.
 *
 * The sentinel "ALL" kantor (Kantor::SENTINEL_ALL_KODE) is excluded from the
 * list and hard-blocked from update()/toggleActive() — it's dashboard_summary's
 * internal global-aggregate row, never a real Cabang to rename/deactivate.
 */
class KantorController extends Controller
{
    public function index(): View
    {
        $kantorList = Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get();

        return view('kantor.index', ['kantorList' => $kantorList]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateKantor($request);

        Kantor::create($data + ['is_active' => true]);

        return back()->with('status', 'Cabang berhasil ditambahkan.');
    }

    public function update(Request $request, Kantor $kantor): RedirectResponse
    {
        abort_if($kantor->kode === Kantor::SENTINEL_ALL_KODE, 404);

        $data = $this->validateKantor($request, $kantor->id);

        $kantor->update($data);

        return back()->with('status', 'Cabang berhasil diperbarui.');
    }

    public function toggleActive(Kantor $kantor): RedirectResponse
    {
        abort_if($kantor->kode === Kantor::SENTINEL_ALL_KODE, 404);

        $kantor->update(['is_active' => ! $kantor->is_active]);

        return back()->with('status', $kantor->is_active ? 'Cabang diaktifkan kembali.' : 'Cabang dinonaktifkan.');
    }

    /**
     * Area/Cabang-Cluster are optional here (nullable) — the inline single-
     * row form is meant for quick Kode/Nama fixes too, not just the full
     * hierarchy setup that's normally done via the bulk Excel round-trip.
     */
    private function validateKantor(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:255', Rule::unique('kantor', 'kode')->ignore($ignoreId)],
            'nama' => ['required', 'string', 'max:255', Rule::unique('kantor', 'nama')->ignore($ignoreId)],
            'area' => ['nullable', 'string', 'max:255'],
            'cabang_cluster' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
