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
}
