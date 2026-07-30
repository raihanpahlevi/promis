<?php

namespace App\Http\Controllers;

use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\Poi;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
 *
 * Kantor filter is multi-select (2026-07-22) — `kantor[]=1&kantor[]=2` in the
 * query string, or a single legacy `kantor=1` still works the same way
 * (resolveKantorScope() normalizes either shape). Selecting none keeps the
 * original "everything in the user's scope" default (admin: every kantor;
 * admin_final: their own assignment). The per-kantor histogram
 * (histogramData()) and the new tidak-kunjungan summary both already loop
 * over exactly $kantorScope['kantorIds'] — narrowing that array to a
 * multi-select subset (instead of always "every kantor in scope") is the
 * whole fix for both rendering too many bars/cards when a deployment has a
 * lot of kantor.
 */
class LaporanController extends Controller
{
    private const RELEVANT_ROLES = [User::ROLE_SALES, User::ROLE_ADMIN_FINAL];

    /**
     * Funnel stages as they appear across the Summary Kunjungan table, left to
     * right. Closing is deliberately last: the source spreadsheet kept it in a
     * column of its own beside the running total, and the report mirrors that.
     */
    private const FUNNEL_STAGES = [
        Kunjungan::HASIL_BELUM_BERTEMU,
        Kunjungan::HASIL_BELUM_BERMINAT,
        Kunjungan::HASIL_BERMINAT,
        Kunjungan::HASIL_NEGO_PRICING,
        Kunjungan::HASIL_COLLECTING_DOKUMEN,
        Kunjungan::HASIL_CLOSING,
    ];

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

        // Both the row list AND its per-kantor/per-unit breakdown are only
        // fetched for whichever tab is actually active (2026-07-22 revision —
        // previously the "tidak" data/summary loaded unconditionally on every
        // request, which made the page feel cluttered with a breakdown that
        // didn't match what the table below it was showing). Switching tabs
        // now genuinely changes every number on the page below the 3 top-line
        // stat cards, not just the table.
        $kunjunganRows = null;
        $kunjunganSummary = collect();
        $tidakRows = null;
        $tidakSummary = collect();

        if ($mode === 'kunjungan') {
            $kunjunganRows = $this->kunjunganRekap($kantorScope['kantorIds'], $unitId, $dari, $sampai);
            $kunjunganSummary = $this->kunjunganSummary($kunjunganRows, $kantorScope['kantorIds']);
        } else {
            $tidakRows = $this->tidakKunjunganRekap($kantorScope['kantorIds'], $unitId, $dari, $sampai);
            $tidakSummary = $this->tidakKunjunganSummary($tidakRows, $kantorScope['kantorIds']);
        }

        $kantorList = Kantor::whereIn('id', $kantorScope['kantorIds'])->orderBy('nama')->get();
        $histogram = $this->histogramData($kantorList, $unitId, $dari, $sampai);

        return view('laporan.rekap-sales', [
            'mode' => $mode,
            'dari' => $dari,
            'sampai' => $sampai,
            'unitOptions' => $unitOptions,
            'unitId' => $unitId,
            'kantorOptions' => $kantorScope['kantorOptions'],
            'selectedKantorIds' => $kantorScope['selectedKantorIds'],
            'kantorAreaOptions' => $kantorScope['areaOptions'],
            'selectedKantorArea' => $kantorScope['selectedArea'],
            'kantorClusterOptions' => $kantorScope['clusterOptions'],
            'selectedKantorClusters' => $kantorScope['selectedClusters'],
            'kunjunganRows' => $kunjunganRows,
            'kunjunganSummary' => $kunjunganSummary,
            'tidakRows' => $tidakRows,
            'tidakSummary' => $tidakSummary,
            'histogram' => $histogram,
        ]);
    }

    /**
     * Same admin/admin_final kantor-scoping shape as HistogramController —
     * admin: free choice across every kantor; admin_final: bounded to their
     * own assignment, forged kantor ids outside that scope are dropped
     * rather than trusted.
     *
     * Layered with an Area -> Cabang-Cluster -> Cabang drill-down
     * (2026-07-23, same shape as DashboardController::buildHierarchicalScope
     * — duplicated here rather than shared, since Dashboard's version also
     * computes its own kantorLabel string this page doesn't need, and this
     * was the pragmatic call given the size of everything else that shipped
     * alongside it): Area is a single value, Cabang-Cluster/Cabang are both
     * multi-select (`cluster[]=`/`kantor[]=`, or a single legacy scalar —
     * collect() normalizes either shape). Selecting nothing at any level
     * falls back to the user's full scope, same as before.
     */
    private function resolveKantorScope(User $user, Request $request): array
    {
        $allowedKantor = $user->isAdmin()
            ? Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get()
            : $user->kantor()->orderBy('nama')->get();

        $areaOptions = $allowedKantor->pluck('area')->filter()->unique()->sort()->values();
        $selectedArea = $request->filled('area') && $areaOptions->contains($request->input('area'))
            ? $request->input('area')
            : null;

        $kantorInArea = $selectedArea !== null
            ? $allowedKantor->where('area', $selectedArea)->values()
            : $allowedKantor;

        $clusterOptions = $kantorInArea->pluck('cabang_cluster')->filter()->unique()->sort()->values();
        $requestedClusters = collect($request->input('cluster', []))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (string) $v)
            ->unique()
            ->values()
            ->all();
        $selectedClusters = array_values(array_intersect($requestedClusters, $clusterOptions->all()));

        $kantorInCluster = $selectedClusters !== []
            ? $kantorInArea->whereIn('cabang_cluster', $selectedClusters)->values()
            : $kantorInArea;

        $requestedIds = collect($request->input('kantor', []))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
        $allowedIdsAfterCluster = $kantorInCluster->pluck('id')->all();
        $selectedKantorIds = array_values(array_intersect($requestedIds, $allowedIdsAfterCluster));

        return [
            'kantorIds' => $selectedKantorIds !== [] ? $selectedKantorIds : $allowedIdsAfterCluster,
            'kantorOptions' => $kantorInCluster,
            'selectedKantorIds' => $selectedKantorIds,
            'areaOptions' => $areaOptions,
            'selectedArea' => $selectedArea,
            'clusterOptions' => $clusterOptions,
            'selectedClusters' => $selectedClusters,
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
     * Groups the SAME collection tidakKunjunganRekap() already fetched —
     * per kantor, then per unit within that kantor — instead of re-querying
     * from scratch (2026-07-22, product request: "jangan query ulang dari
     * nol kalau bisa reuse").
     *
     * user_kantor is many-to-many, so a user assigned to more than one kantor
     * within $kantorIds is counted under every one of those kantor — this is
     * consistent with tidakKunjunganRekap()'s own row for that user, which
     * already lists every one of their kantor via $u->kantor->pluck('nama');
     * this summary is just that same membership regrouped into per-kantor
     * buckets, not a narrower definition of "belongs to". A kantor the user
     * is also assigned to but that falls OUTSIDE $kantorIds (i.e. outside the
     * currently active filter) is skipped, so the summary never shows a
     * kantor the admin didn't actually select/scope into.
     *
     * @param  Collection<int, User>  $tidakRows
     * @param  int[]  $kantorIds
     * @return Collection<int, array{kantor: Kantor, units: Collection<int, array{nama: string, jumlah: int}>, total: int}>
     */
    private function tidakKunjunganSummary(Collection $tidakRows, array $kantorIds): Collection
    {
        /** @var array<int, array{kantor: Kantor, units: array<string, int>}> $buckets */
        $buckets = [];

        foreach ($tidakRows as $u) {
            $unitLabel = $u->unit->nama ?? 'Tanpa Unit';

            foreach ($u->kantor as $kantor) {
                if (! in_array($kantor->id, $kantorIds, true)) {
                    continue;
                }

                $buckets[$kantor->id]['kantor'] ??= $kantor;
                $buckets[$kantor->id]['units'][$unitLabel] = ($buckets[$kantor->id]['units'][$unitLabel] ?? 0) + 1;
            }
        }

        return collect($buckets)
            ->map(function (array $bucket): array {
                $units = collect($bucket['units'])
                    ->map(fn (int $jumlah, string $nama) => ['nama' => $nama, 'jumlah' => $jumlah])
                    ->sortByDesc('jumlah')
                    ->values();

                return [
                    'kantor' => $bucket['kantor'],
                    'units' => $units,
                    'total' => $units->sum('jumlah'),
                ];
            })
            ->sortBy(fn (array $row) => $row['kantor']->nama)
            ->values();
    }

    /**
     * Same shape/reuse principle as tidakKunjunganSummary() (2026-07-22),
     * mirrored for the "Kunjungan" tab: groups the SAME collection
     * kunjunganRekap() already fetched — per kantor, then per unit — instead
     * of re-querying. Sums total_visit/total_closing (already computed by
     * kunjunganRekap()'s withCount()) per unit rather than counting heads,
     * since the "kunjungan" side cares about visit volume, not just how many
     * distinct sales visited.
     *
     * @param  Collection<int, User>  $kunjunganRows
     * @param  int[]  $kantorIds
     * @return Collection<int, array{kantor: Kantor, units: Collection<int, array{nama: string, visit: int, closing: int}>, total: int}>
     */
    private function kunjunganSummary(Collection $kunjunganRows, array $kantorIds): Collection
    {
        /** @var array<int, array{kantor: Kantor, units: array<string, array{visit: int, closing: int}>}> $buckets */
        $buckets = [];

        foreach ($kunjunganRows as $u) {
            $unitLabel = $u->unit->nama ?? 'Tanpa Unit';

            foreach ($u->kantor as $kantor) {
                if (! in_array($kantor->id, $kantorIds, true)) {
                    continue;
                }

                $buckets[$kantor->id]['kantor'] ??= $kantor;
                $buckets[$kantor->id]['units'][$unitLabel]['visit'] = ($buckets[$kantor->id]['units'][$unitLabel]['visit'] ?? 0) + $u->total_visit;
                $buckets[$kantor->id]['units'][$unitLabel]['closing'] = ($buckets[$kantor->id]['units'][$unitLabel]['closing'] ?? 0) + $u->total_closing;
            }
        }

        return collect($buckets)
            ->map(function (array $bucket): array {
                $units = collect($bucket['units'])
                    ->map(fn (array $counts, string $nama) => ['nama' => $nama, 'visit' => $counts['visit'], 'closing' => $counts['closing']])
                    ->sortByDesc('visit')
                    ->values();

                return [
                    'kantor' => $bucket['kantor'],
                    'units' => $units,
                    'total' => $units->sum('visit'),
                ];
            })
            ->sortBy(fn (array $row) => $row['kantor']->nama)
            ->values();
    }

    /**
     * Both histograms are per-kantor on the X-axis (v1's "per jabatan" chart
     * is a misleading name — it's the same per-kantor loop, just showing the
     * tidak-kunjungan headcount as a real bar instead of a fixed-height
     * marker, and labeling the axis with the unit name when one is
     * selected). Looped per-kantor (one query set per kantor) rather than one
     * grouped query — $kantorList is exactly the multi-select filter's
     * resolved scope (2026-07-22), so this is capped at however many kantor
     * the admin actually picked, not every kantor they're allowed to see.
     *
     * 'summary' (2026-07-22) is a pure aggregate of the same per-kantor totals
     * already computed in this loop for the two chart datasets — accumulated
     * inline as the loop runs, not a re-query, so the top-line stat cards
     * cost nothing extra over the histograms themselves. kantor_aktif counts
     * a kantor as "aktif" when it has >=1 kunjungan in range (i.e. its
     * $totalKunj > 0 for this same dari/sampai/unit filter) — an independent
     * definition from "tidak kunjungan" (that's about individual sales users
     * having zero visits, not the kantor as a whole). Closing/conversion-rate
     * are NOT included here (2026-07-22 revision, product request to drop
     * that stat card) even though $totalClosingKantor is still tallied per
     * kantor below — it's still needed for the "Closing" bars in both chart
     * datasets, just no longer rolled into a page-level aggregate.
     */
    private function histogramData(Collection $kantorList, ?int $unitId, string $dari, string $sampai): array
    {
        $unitLabel = $unitId ? optional(Unit::find($unitId))->nama : null;

        $labels = $kunj = $closing = $marker = [];
        $labels2 = $kunj2 = $tidak2 = $closing2 = [];

        $totalKunjunganSum = 0;
        $totalTidakSum = 0;
        $kantorAktif = 0;

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
            $totalClosingKantor = Kunjungan::query()->tap($visitScope)->where('hasil', Kunjungan::HASIL_CLOSING)->count();

            $totalTidakKantor = $this->relevantUserQuery($unitId)
                ->whereHas('kantor', fn ($q) => $q->where('kantor.id', $kantor->id))
                ->whereDoesntHave('kunjungan', fn ($q) => $q->whereBetween('tanggal_kunjungan', [$dari, $sampai]))
                ->count();

            $labels[] = $kantor->nama;
            $kunj[] = $totalKunj;
            $closing[] = $totalClosingKantor;
            $marker[] = $totalTidakKantor > 0 ? 0.3 : 0;

            $labels2[] = $unitLabel ? $kantor->nama.' ('.$unitLabel.')' : $kantor->nama;
            $kunj2[] = $totalKunj;
            $tidak2[] = $totalTidakKantor;
            $closing2[] = $totalClosingKantor;

            $totalKunjunganSum += $totalKunj;
            $totalTidakSum += $totalTidakKantor;

            if ($totalKunj > 0) {
                $kantorAktif++;
            }
        }

        return [
            'labels' => $labels, 'kunjungan' => $kunj, 'closing' => $closing, 'marker' => $marker,
            'labels2' => $labels2, 'kunjungan2' => $kunj2, 'tidak2' => $tidak2, 'closing2' => $closing2,
            'summary' => [
                'total_kunjungan' => $totalKunjunganSum,
                'total_tidak' => $totalTidakSum,
                'kantor_aktif' => $kantorAktif,
                'total_kantor' => $kantorList->count(),
            ],
        ];
    }

    // =====================================================================
    // Summary Kunjungan & Summary Produk (2026-07-29)
    //
    // Both mirror the two sheets of the "menu tambahan" workbook: one row per
    // Cabang, grouped under its Cabang-Cluster and then its Area, with a
    // subtotal on every group and a grand total at the bottom.
    //
    // Deviations from the spreadsheet, agreed before building:
    //  - "Total Kunjungan" counts Closing too. The sheet's =SUM(D:H) stopped
    //    short of the Closing column, which would report 0 visits for a
    //    Cabang whose visits all closed.
    //  - "%-Tase Closing" keeps the sheet's own basis: Closing / Jumlah POI
    //    (penetration of the POI base), NOT Closing / visits.
    //  - Summary Produk counts products from Closing visits only, matching the
    //    sheet's "Produk Closing" heading and the dashboard's produk panel.
    // Jumlah POI and Jumlah Sales are current stock and deliberately ignore
    // the date filter; only the visit-derived columns move with it.
    // =====================================================================

    public function summaryKunjungan(Request $request): View
    {
        $user = $request->user();
        $kantorScope = $this->resolveKantorScope($user, $request);
        [$dari, $sampai] = $this->summaryPeriode($request);

        $kantorList = $kantorScope['kantorOptions']
            ->whereIn('id', $kantorScope['kantorIds'])
            ->values();
        $kantorIds = $kantorList->pluck('id')->all();

        $poiPerKantor = Poi::query()
            ->whereIn('kantor_id', $kantorIds)
            ->where('status', 'aktif')
            ->selectRaw('kantor_id, COUNT(*) as n')
            ->groupBy('kantor_id')
            ->pluck('n', 'kantor_id');

        // Same population Rekap Sales counts (relevantUserQuery: sales/
        // admin_final, with an active unit) so the two tabs can't disagree on
        // one Cabang's headcount. Kept as raw ids, not per-kantor counts: one
        // sales can hold several Cabang, so the Area/Cluster/grand rows have
        // to union the ids rather than add the counts up — see
        // buildKantorHierarchy()'s $distinctSets.
        $salesIdsPerKantor = DB::table('user_kantor')
            ->whereIn('kantor_id', $kantorIds)
            ->whereIn('user_id', $this->relevantUserQuery(null)->where('is_active', true)->pluck('id'))
            ->get(['kantor_id', 'user_id'])
            ->groupBy('kantor_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->all())
            ->all();

        $hasilPerKantor = Kunjungan::query()
            ->join('poi', 'poi.id', '=', 'kunjungan.poi_id')
            ->whereIn('poi.kantor_id', $kantorIds)
            // Matches the Jumlah POI column beside it — without this a Cabang
            // could report visits against POI the same row says don't exist,
            // pushing %-Tase Closing past 100%.
            ->where('poi.status', 'aktif')
            ->whereBetween('kunjungan.tanggal_kunjungan', [$dari, $sampai])
            ->selectRaw('poi.kantor_id, kunjungan.hasil, COUNT(*) as n')
            ->groupBy('poi.kantor_id', 'kunjungan.hasil')
            ->get();

        $metrics = [];
        foreach ($kantorList as $kantor) {
            $row = ['jumlah_poi' => (int) ($poiPerKantor[$kantor->id] ?? 0)];
            foreach (self::FUNNEL_STAGES as $stage) {
                $row[$stage] = 0;
            }
            $metrics[$kantor->id] = $row;
        }
        foreach ($hasilPerKantor as $r) {
            if (isset($metrics[$r->kantor_id]) && array_key_exists($r->hasil, $metrics[$r->kantor_id])) {
                $metrics[$r->kantor_id][$r->hasil] = (int) $r->n;
            }
        }

        $columns = array_merge(['jumlah_poi'], self::FUNNEL_STAGES);
        $rows = $this->buildKantorHierarchy(
            $kantorList,
            $metrics,
            $columns,
            ['jumlah_sales' => $salesIdsPerKantor],
        );

        return view('laporan.summary-kunjungan', [
            'rows' => $rows,
            'stages' => self::FUNNEL_STAGES,
            'dari' => $dari,
            'sampai' => $sampai,
        ] + $this->summaryFilterViewData($kantorScope));
    }

    public function summaryProduk(Request $request): View
    {
        $user = $request->user();
        $kantorScope = $this->resolveKantorScope($user, $request);
        [$dari, $sampai] = $this->summaryPeriode($request);

        $kantorList = $kantorScope['kantorOptions']
            ->whereIn('id', $kantorScope['kantorIds'])
            ->values();
        $kantorIds = $kantorList->pluck('id')->all();

        $produkRows = DB::table('kunjungan_produk')
            ->join('kunjungan', 'kunjungan.id', '=', 'kunjungan_produk.kunjungan_id')
            ->join('poi', 'poi.id', '=', 'kunjungan.poi_id')
            ->whereIn('poi.kantor_id', $kantorIds)
            ->where('poi.status', 'aktif') // same basis as Summary Kunjungan
            ->where('kunjungan.hasil', Kunjungan::HASIL_CLOSING)
            ->whereBetween('kunjungan.tanggal_kunjungan', [$dari, $sampai])
            ->selectRaw('poi.kantor_id, kunjungan_produk.produk, COUNT(*) as n')
            ->groupBy('poi.kantor_id', 'kunjungan_produk.produk')
            ->get();

        $produkList = Kunjungan::PRODUK_OPTIONS;

        $metrics = [];
        foreach ($kantorList as $kantor) {
            $metrics[$kantor->id] = array_fill_keys($produkList, 0);
        }
        foreach ($produkRows as $r) {
            if (isset($metrics[$r->kantor_id]) && array_key_exists($r->produk, $metrics[$r->kantor_id])) {
                $metrics[$r->kantor_id][$r->produk] = (int) $r->n;
            }
        }

        $rows = $this->buildKantorHierarchy($kantorList, $metrics, $produkList);

        return view('laporan.summary-produk', [
            'rows' => $rows,
            'produkList' => $produkList,
            'dari' => $dari,
            'sampai' => $sampai,
        ] + $this->summaryFilterViewData($kantorScope));
    }

    /**
     * Validated so a hand-edited or stale URL can't 500 the page: the raw
     * value reaches Carbon::parse() in the period badge (?dari=abc threw
     * "Could not parse") and htmlspecialchars() in the filter input
     * (?dari[]=... threw on an array). The SQL itself was never at risk —
     * these are bound parameters — but the render was.
     *
     * @return array{0: string, 1: string}
     */
    private function summaryPeriode(Request $request): array
    {
        $valid = $request->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $dari = $valid['dari'] ?? Carbon::today()->startOfMonth()->toDateString();
        $sampai = $valid['sampai'] ?? Carbon::today()->toDateString();

        return [$dari, $sampai];
    }

    private function summaryFilterViewData(array $kantorScope): array
    {
        return [
            'kantorOptions' => $kantorScope['kantorOptions'],
            'selectedKantorIds' => $kantorScope['selectedKantorIds'],
            'kantorAreaOptions' => $kantorScope['areaOptions'],
            'selectedKantorArea' => $kantorScope['selectedArea'],
            'kantorClusterOptions' => $kantorScope['clusterOptions'],
            'selectedKantorClusters' => $kantorScope['selectedClusters'],
        ];
    }

    /**
     * Flattens Area > Cabang-Cluster > Cabang into the ordered row list the
     * views render, injecting a subtotal row for every group plus a grand
     * total, exactly like the source spreadsheet.
     *
     * Each returned row is ['level' => 'area'|'cluster'|'cabang'|'total',
     * 'label' => string, 'values' => [column => int]]. Kantor with no
     * area/cluster recorded are grouped under an explicit placeholder rather
     * than silently dropped — otherwise their numbers would vanish from a
     * report whose grand total is supposed to reconcile.
     *
     * $distinctSets holds columns that must NOT be added up the tree because
     * the thing being counted can belong to more than one Cabang — currently
     * just Jumlah Sales, since a sales can hold several Cabang and adding the
     * per-Cabang counts reported one person twice in the Cluster, Area and
     * grand-total rows. Each entry maps kantor id => list of member ids; every
     * level unions the ids it covers and counts the union instead.
     *
     * @param  array<int, array<string, int>>  $metrics  keyed by kantor id
     * @param  array<int, string>  $columns  additive columns
     * @param  array<string, array<int, array<int, mixed>>>  $distinctSets  column => kantor id => member ids
     * @return array<int, array{level: string, label: string, values: array<string, int>}>
     */
    private function buildKantorHierarchy(
        Collection $kantorList,
        array $metrics,
        array $columns,
        array $distinctSets = [],
    ): array {
        $zero = array_fill_keys($columns, 0);
        $sum = function (array $a, array $b) use ($columns) {
            foreach ($columns as $c) {
                $a[$c] += $b[$c] ?? 0;
            }

            return $a;
        };

        // Collects the member ids seen under a group, then folds their counts
        // into that group's row once the group is complete.
        $emptySets = array_fill_keys(array_keys($distinctSets), []);
        $mergeSets = function (array $acc, int $kantorId) use ($distinctSets) {
            foreach ($distinctSets as $column => $byKantor) {
                foreach ($byKantor[$kantorId] ?? [] as $memberId) {
                    $acc[$column][$memberId] = true;
                }
            }

            return $acc;
        };
        $withCounts = function (array $values, array $acc) use ($distinctSets) {
            foreach (array_keys($distinctSets) as $column) {
                $values[$column] = count($acc[$column] ?? []);
            }

            return $values;
        };

        $grouped = $kantorList
            ->sortBy(fn ($k) => [$k->area ?? '', $k->cabang_cluster ?? '', $k->nama])
            ->groupBy(fn ($k) => $k->area ?: 'Tanpa Area');

        $rows = [];
        $grand = $zero;
        $grandSets = $emptySets;

        foreach ($grouped as $area => $kantorInArea) {
            $areaTotal = $zero;
            $areaSets = $emptySets;
            $clusterBlocks = [];

            foreach ($kantorInArea->groupBy(fn ($k) => $k->cabang_cluster ?: 'Tanpa Cluster') as $cluster => $kantorInCluster) {
                $clusterTotal = $zero;
                $clusterSets = $emptySets;
                $cabangRows = [];

                foreach ($kantorInCluster as $kantor) {
                    $values = ($metrics[$kantor->id] ?? []) + $zero;
                    $clusterTotal = $sum($clusterTotal, $values);
                    $clusterSets = $mergeSets($clusterSets, $kantor->id);
                    $areaSets = $mergeSets($areaSets, $kantor->id);
                    $grandSets = $mergeSets($grandSets, $kantor->id);

                    $cabangRows[] = [
                        'level' => 'cabang',
                        'label' => $kantor->nama,
                        'values' => $withCounts($values, $mergeSets($emptySets, $kantor->id)),
                    ];
                }

                $areaTotal = $sum($areaTotal, $clusterTotal);
                $clusterBlocks[] = array_merge(
                    [[
                        'level' => 'cluster',
                        'label' => $cluster,
                        'values' => $withCounts($clusterTotal, $clusterSets),
                    ]],
                    $cabangRows,
                );
            }

            $grand = $sum($grand, $areaTotal);
            $rows[] = ['level' => 'area', 'label' => $area, 'values' => $withCounts($areaTotal, $areaSets)];
            foreach ($clusterBlocks as $block) {
                foreach ($block as $row) {
                    $rows[] = $row;
                }
            }
        }

        $rows[] = ['level' => 'total', 'label' => 'TOTAL', 'values' => $withCounts($grand, $grandSets)];

        return $rows;
    }
}
