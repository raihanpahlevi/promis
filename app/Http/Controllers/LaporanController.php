<?php

namespace App\Http\Controllers;

use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * "Laporan" hub — Riwayat Kunjungan (KunjunganController::index), Rekap Sales
 * (this controller), and Export Data (ExportController::index) are one merged
 * sidebar entry sharing a tab bar (resources/views/laporan/_tabs.blade.php),
 * rather than three separate routes/controllers merged into one — each tab's
 * existing, already-tested controller stays untouched.
 *
 * Rekap Sales itself is rebuilt from the real v1 list_user.php: two internal
 * tabs — "Kunjungan" (per-sales visit/closing totals) and "Tidak Kunjungan"
 * (who logged zero visits in the period) — plus two histograms, all
 * filterable by tanggal/unit/kantor. Only `sales` and `admin_final` users are
 * ever in scope (matches v1 — `admin` doesn't do field visits).
 *
 * v1 hardcoded which units count via a fixed PHP array
 * (`AH,BM,BBM,...` excluded). This version has no such hardcoding: every
 * query here is scoped to `unit.is_active` — an admin toggles that from
 * Pengaturan (UnitController) and it takes effect everywhere immediately, no
 * code change needed.
 */
class LaporanController extends Controller
{
    private const RELEVANT_ROLES = [User::ROLE_SALES, User::ROLE_ADMIN_FINAL];

    public function rekapSales(Request $request): View
    {
        $user = $request->user();
        $kantorScope = $this->resolveKantorScope($user, $request);

        $unitOptions = Unit::where('is_active', true)->orderBy('nama')->get();
        $unitId = $request->filled('unit') ? (int) $request->input('unit') : null;
        if ($unitId !== null && ! $unitOptions->contains('id', $unitId)) {
            $unitId = null;
        }

        $dari = $request->filled('dari') ? $request->input('dari') : Carbon::today()->toDateString();
        $sampai = $request->filled('sampai') ? $request->input('sampai') : Carbon::today()->toDateString();

        $mode = $request->input('mode') === 'tidak' ? 'tidak' : 'kunjungan';

        $kunjunganRows = $mode === 'kunjungan'
            ? $this->kunjunganRekap($kantorScope['kantorIds'], $unitId, $dari, $sampai)
            : null;

        $tidakRows = $mode === 'tidak'
            ? $this->tidakKunjunganRekap($kantorScope['kantorIds'], $unitId, $dari, $sampai)
            : null;

        $kantorList = Kantor::whereIn('id', $kantorScope['kantorIds'])->orderBy('nama')->get();
        $histogram = $this->histogramData($kantorList, $unitId, $dari, $sampai);

        return view('laporan.rekap-sales', [
            'mode' => $mode,
            'dari' => $dari,
            'sampai' => $sampai,
            'unitOptions' => $unitOptions,
            'unitId' => $unitId,
            'kantorOptions' => $kantorScope['kantorOptions'],
            'selectedKantorId' => $kantorScope['selectedKantorId'],
            'kunjunganRows' => $kunjunganRows,
            'tidakRows' => $tidakRows,
            'histogram' => $histogram,
        ]);
    }

    /**
     * Same admin/admin_final kantor-scoping shape as HistogramController —
     * admin: free choice across every kantor; admin_final: bounded to their
     * own assignment, a forged kantor id outside it is ignored.
     */
    private function resolveKantorScope(User $user, Request $request): array
    {
        if ($user->isAdmin()) {
            $allKantor = Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get();
            $selected = $request->filled('kantor') ? (int) $request->input('kantor') : null;
            if ($selected !== null && ! $allKantor->contains('id', $selected)) {
                $selected = null;
            }

            return [
                'kantorIds' => $selected !== null ? [$selected] : $allKantor->pluck('id')->all(),
                'kantorOptions' => $allKantor,
                'selectedKantorId' => $selected,
            ];
        }

        $owned = $user->kantor()->orderBy('nama')->get();
        $ownedIds = $owned->pluck('id')->all();
        $selected = $request->filled('kantor') ? (int) $request->input('kantor') : null;
        if ($selected !== null && ! in_array($selected, $ownedIds, true)) {
            $selected = null;
        }

        return [
            'kantorIds' => $selected !== null ? [$selected] : $ownedIds,
            'kantorOptions' => $owned,
            'selectedKantorId' => $selected,
        ];
    }

    private function relevantUserQuery(?int $unitId)
    {
        return User::query()
            ->whereIn('role', self::RELEVANT_ROLES)
            ->whereNotNull('unit_id')
            ->whereHas('unit', fn ($q) => $q->where('is_active', true))
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId));
    }

    /**
     * Only users with >=1 kunjungan in range appear here (an inner-join shape
     * in v1's raw SQL) — zero-visit users belong exclusively to the "tidak"
     * tab below, the two are mutually exclusive by construction.
     */
    private function kunjunganRekap(array $kantorIds, ?int $unitId, string $dari, string $sampai): Collection
    {
        $visitScope = function ($q) use ($kantorIds, $dari, $sampai) {
            $q->whereHas('poi', fn ($pq) => $pq->whereIn('kantor_id', $kantorIds))
                ->whereBetween('tanggal_kunjungan', [$dari, $sampai]);
        };

        return $this->relevantUserQuery($unitId)
            ->with('unit', 'kantor')
            ->whereHas('kunjungan', $visitScope)
            ->withCount([
                'kunjungan as total_visit' => $visitScope,
                'kunjungan as total_closing' => function ($q) use ($kantorIds, $dari, $sampai) {
                    $q->whereHas('poi', fn ($pq) => $pq->whereIn('kantor_id', $kantorIds))
                        ->whereBetween('tanggal_kunjungan', [$dari, $sampai])
                        ->where('hasil', Kunjungan::HASIL_CLOSING);
                },
            ])
            ->orderBy('nama_lengkap')
            ->get();
    }

    /**
     * Scoped by the USER's assigned kantor (user_kantor), not the POI's —
     * there's no visit to derive a kantor from here, that's the whole point
     * of this tab. Matches v1's own asymmetry (its "kunjungan" query filters
     * by ks.kantor, its "tidak" query filters by uk.kantor).
     */
    private function tidakKunjunganRekap(array $kantorIds, ?int $unitId, string $dari, string $sampai): Collection
    {
        return $this->relevantUserQuery($unitId)
            ->with('unit', 'kantor')
            ->whereHas('kantor', fn ($q) => $q->whereIn('kantor.id', $kantorIds))
            ->whereDoesntHave('kunjungan', fn ($q) => $q->whereBetween('tanggal_kunjungan', [$dari, $sampai]))
            ->orderBy('nama_lengkap')
            ->get();
    }

    /**
     * Both histograms are per-kantor on the X-axis (v1's "per jabatan" chart
     * is a misleading name — it's the same per-kantor loop, just showing the
     * tidak-kunjungan headcount as a real bar instead of a fixed-height
     * marker, and labeling the axis with the unit name when one is
     * selected). Looped per-kantor (3-4 queries each) rather than one grouped
     * query — kantor count is small (tens), this isn't the 184k-row hot path.
     */
    private function histogramData(Collection $kantorList, ?int $unitId, string $dari, string $sampai): array
    {
        $unitLabel = $unitId ? optional(Unit::find($unitId))->nama : null;

        $labels = $kunj = $closing = $marker = [];
        $labels2 = $kunj2 = $tidak2 = $closing2 = [];

        foreach ($kantorList as $kantor) {
            $visitScope = function ($q) use ($kantor, $unitId, $dari, $sampai) {
                $q->whereHas('poi', fn ($pq) => $pq->where('kantor_id', $kantor->id))
                    ->whereHas('sales', function ($sq) use ($unitId) {
                        $sq->whereIn('role', self::RELEVANT_ROLES)
                            ->whereNotNull('unit_id')
                            ->whereHas('unit', fn ($uq) => $uq->where('is_active', true))
                            ->when($unitId, fn ($q2) => $q2->where('unit_id', $unitId));
                    })
                    ->whereBetween('tanggal_kunjungan', [$dari, $sampai]);
            };

            $totalKunj = Kunjungan::query()->tap($visitScope)->count();
            $totalClosing = Kunjungan::query()->tap($visitScope)->where('hasil', Kunjungan::HASIL_CLOSING)->count();

            $totalTidak = $this->relevantUserQuery($unitId)
                ->whereHas('kantor', fn ($q) => $q->where('kantor.id', $kantor->id))
                ->whereDoesntHave('kunjungan', fn ($q) => $q->whereBetween('tanggal_kunjungan', [$dari, $sampai]))
                ->count();

            $labels[] = $kantor->nama;
            $kunj[] = $totalKunj;
            $closing[] = $totalClosing;
            $marker[] = $totalTidak > 0 ? 0.3 : 0;

            $labels2[] = $unitLabel ? $kantor->nama.' ('.$unitLabel.')' : $kantor->nama;
            $kunj2[] = $totalKunj;
            $tidak2[] = $totalTidak;
            $closing2[] = $totalClosing;
        }

        return [
            'labels' => $labels, 'kunjungan' => $kunj, 'closing' => $closing, 'marker' => $marker,
            'labels2' => $labels2, 'kunjungan2' => $kunj2, 'tidak2' => $tidak2, 'closing2' => $closing2,
        ];
    }
}
