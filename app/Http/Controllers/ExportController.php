<?php

namespace App\Http\Controllers;

use App\Exports\KunjunganExport;
use App\Models\Kantor;
use App\Models\Kunjungan;
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
}
