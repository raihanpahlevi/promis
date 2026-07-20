<?php

namespace App\Http\Controllers;

use App\Exports\KunjunganExport;
use App\Exports\PoiExport;
use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\Poi;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Export & Laporan" (PRD §3 item 6) — real .xlsx via PhpSpreadsheet
 * (KunjunganExport), not the old system's HTML-disguised-as-.xls file.
 * admin/admin_final only, same kantor-scoping rule as the Kunjungan riwayat
 * screen this mirrors (KunjunganController::index).
 *
 * No separate "Export Data" browsing page anymore — merged into Riwayat
 * Kunjungan (KunjunganController::index / kunjungan/index.blade.php), which
 * has an "Export Excel" button passing through whatever filters are
 * currently active on that table. This is just the download endpoint.
 */
class ExportController extends Controller
{
    public function download(Request $request): BinaryFileResponse
    {
        $user = $request->user();

        $filters = $request->validate([
            'hasil' => ['nullable', 'string', Rule::in(Kunjungan::HASIL_OPTIONS)],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date'],
            'kantor_id' => ['nullable', 'integer'],
            'sales_id' => ['nullable', 'integer'],
            'poi' => ['nullable', 'string', 'max:255'],
        ]);

        $allowedKantorIds = $user->isAdminFinal()
            ? $user->kantor()->pluck('kantor.id')->all()
            : Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->pluck('id')->all();

        $kantorId = $filters['kantor_id'] ?? null;
        // A forged kantor_id outside the acting user's scope must not narrow to
        // (and thereby confirm the existence of) a kantor they don't own — fall
        // back to their full allowed scope instead of trusting the filter value.
        $kantorIds = ($kantorId && in_array((int) $kantorId, $allowedKantorIds, true))
            ? [(int) $kantorId]
            : $allowedKantorIds;

        $salesId = $filters['sales_id'] ?? null;
        if ($salesId && $user->isAdminFinal()) {
            $ownsSales = User::where('id', $salesId)
                ->whereHas('kantor', fn ($q) => $q->whereIn('kantor.id', $allowedKantorIds))
                ->exists();
            $salesId = $ownsSales ? (int) $salesId : null;
        }

        $export = new KunjunganExport(
            kantorIds: $kantorIds,
            hasil: $filters['hasil'] ?? null,
            dari: $filters['dari'] ?? null,
            sampai: $filters['sampai'] ?? null,
            salesId: $salesId ? (int) $salesId : null,
            poi: $filters['poi'] ?? null,
        );

        $filename = 'rekap-kunjungan-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download($export, $filename);
    }

    /**
     * POI export — carries the same filters as PoiController::index's table
     * (kantor scope resolved the same way: admin sees everything with an
     * optional narrow filter, admin_final locked to `user_kantor`, a forged
     * kantor filter outside that scope is ignored rather than trusted). The
     * "ID" column this includes is what lets the file round-trip back through
     * PoiImport as an update instead of creating duplicates — see PoiExport.
     */
    public function downloadPoi(Request $request): BinaryFileResponse
    {
        $user = $request->user();

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:aktif,nonaktif'],
            'area' => ['nullable', 'string', 'max:255'],
            'sektor' => ['nullable', 'string', 'max:255'],
            'status_mitra' => ['nullable', 'string', Rule::in(Poi::STATUS_MITRA_OPTIONS)],
            'q' => ['nullable', 'string', 'max:255'],
            'kantor' => ['nullable', 'integer'],
        ]);

        $allowedKantorIds = $user->isAdminFinal()
            ? $user->kantor()->pluck('kantor.id')->all()
            : Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->pluck('id')->all();

        $kantorId = $filters['kantor'] ?? null;
        // Same rule as the Kunjungan export: a forged kantor id outside the
        // acting user's scope must not narrow to it — fall back to their
        // full allowed scope instead of trusting the filter value.
        $kantorIds = ($kantorId && in_array((int) $kantorId, $allowedKantorIds, true))
            ? [(int) $kantorId]
            : $allowedKantorIds;

        $export = new PoiExport(
            kantorIds: $kantorIds,
            status: $filters['status'] ?? null,
            area: $filters['area'] ?? null,
            sektor: $filters['sektor'] ?? null,
            statusMitra: $filters['status_mitra'] ?? null,
            q: $filters['q'] ?? null,
        );

        $filename = 'data-poi-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download($export, $filename);
    }
}
