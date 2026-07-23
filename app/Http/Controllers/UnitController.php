<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin-managed master list of job units (PRD-adjacent decision, not in the original
 * PRD text — replaces the free-text `unit_jabatan` field with a proper master table,
 * same pattern as `kantor`, so the future "who hasn't visited, by unit" monitoring
 * feature has consistent values to group by instead of v1's hardcoded PHP array).
 */
class UnitController extends Controller
{
    public function index(): View
    {
        $units = Unit::orderBy('nama')->get();

        return view('units.index', ['units' => $units]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('unit', 'nama')],
        ]);

        Unit::create(['nama' => $data['nama'], 'is_active' => true]);

        return back()->with('status', 'Unit berhasil ditambahkan.');
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('unit', 'nama')->ignore($unit->id)],
        ]);

        $unit->update(['nama' => $data['nama']]);

        return back()->with('status', 'Unit berhasil diperbarui.');
    }

    public function toggleActive(Unit $unit): RedirectResponse
    {
        $unit->update(['is_active' => ! $unit->is_active]);

        return back()->with('status', $unit->is_active ? 'Unit diaktifkan kembali.' : 'Unit dinonaktifkan.');
    }

    /**
     * Hard delete, gated on the unit already being nonaktif (added 2026-07-23
     * at product request — deactivated units were piling up as junk in this
     * list). The two-step "nonaktifkan dulu, baru bisa hapus" flow is the
     * misclick guard here; users.unit_id is nullOnDelete, so users still
     * pointing at the unit just lose the assignment (surfaced in the flash
     * message) rather than blocking the delete.
     */
    public function destroy(Unit $unit): RedirectResponse
    {
        if ($unit->is_active) {
            return back()->withErrors([
                'unit' => 'Nonaktifkan unit ini terlebih dahulu sebelum menghapusnya.',
            ]);
        }

        $userCount = $unit->users()->count();
        $nama = $unit->nama;

        $unit->delete();

        $suffix = $userCount > 0 ? " {$userCount} user yang memakai unit ini sekarang tanpa unit." : '';

        return back()->with('status', "Unit {$nama} berhasil dihapus permanen.{$suffix}");
    }
}
