<?php

namespace App\Http\Controllers;

use App\Models\DashboardSummary;
use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\KunjunganProduk;
use App\Models\Poi;
use App\Models\User;
use App\Services\DashboardCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Rebuilt from the real v1 dashboard.php (not the earlier preview mockup —
 * that was visual-direction-only). Logic preserved as-is, including a few
 * quirks that look wrong at first glance but are intentional in the
 * original (documented inline where it matters):
 *
 *  - "BNI" is a binary bucket = Nasabah Non Merchant BNI + Nasabah Merchant
 *    BNI; "Bank Lain"/Non = Bukan Nasabah BNI. status_mitra itself is a
 *    3-value ENUM in this schema, unlike v1's raw 'BNI' string compare.
 *  - Top Area shows all 4 rings (Ring 1-4). Area labels themselves
 *    (Poi::AREA_OPTIONS) are "Ring N (jarak km)" strings, product decision
 *    2026-07-14 — not the schema's old bare "RING 1".."RING 5".
 *  - Top Area BNI/Non both divide by the *all-status* Ring 1-3 total, not
 *    their own status-filtered subtotal — so the three area panels don't
 *    each sum to 100% on their own. That's the original's math, kept as-is.
 *  - "Top Sektor" ranking (which 5 sektor appear) is always driven by BNI
 *    count, even on the Non-BNI panel — both panels show the same 5
 *    sektor, just with different jumlah/persen per status.
 *  - Periode "day"=today, "week"=current calendar month(!), "month"=rolling
 *    45 days. Not a typo, that's the real production semantics.
 *  - Total POI / BNI / Non-BNI stat cards are NOT period-filtered — always
 *    current stock, read from `dashboard_summary` (PRD §5: never
 *    COUNT/SUM the raw poi table for these). Area/Sektor/funnel/produk/chart
 *    breakdowns aren't in dashboard_summary's grain and are inherently
 *    kantor(+period)-scoped already, so those stay live indexed queries —
 *    same approach the real v1 system already used in production.
 */
class DashboardController extends Controller
{
    private const RING_LEVELS = Poi::AREA_OPTIONS;

    private const BNI_STATUSES = ['Nasabah Non Merchant BNI', 'Nasabah Merchant BNI'];

    private const BUKAN_NASABAH = 'Bukan Nasabah BNI';

    public function __construct(private readonly DashboardCache $cache) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $scope = $this->resolveKantorScope($user, $request);
        $kantorIds = $scope['kantorIds'];

        $isFullyUnscoped = $scope['selectedKantorIds'] === [] && $scope['selectedClusters'] === [] && $scope['selectedArea'] === null;
        $totals = $this->resolveTotals($user, $kantorIds, $isFullyUnscoped);

        // The two POI-wide breakdowns are what made this page slow under load —
        // see App\Services\DashboardCache for the measurements. They depend on
        // nothing but the kantor scope, so they cache as-is.
        $area = $this->cache->remember('area', $kantorIds, fn () => $this->areaBreakdown($kantorIds));
        $ringJarak = $this->ringJarakUntukScope($kantorIds);
        $sektor = $this->cache->remember('sektor', $kantorIds, fn () => $this->sektorBreakdown($kantorIds));

        $periode = in_array($request->input('periode'), ['day', 'week', 'month', 'all'], true)
            ? $request->input('periode')
            : 'day';
        [$start, $end] = $this->periodeRange($periode);

        $funnel = $this->ringkasanHasil($kantorIds, $start, $end);
        $produk = $this->produkClosing($kantorIds, $start, $end);
        $cakupan = $this->cakupanCabang($kantorIds, $start, $end);
        $produkCakupan = $this->produkCakupan($kantorIds, $start, $end);
        $closing = $this->closingStats($kantorIds, $start, $end, $totals['total_bni'], $totals['total_non']);

        return view('dashboard', [
            'kantorLabel' => $scope['label'],
            'kantorOptions' => $scope['kantorOptions'],
            'selectedKantorIds' => $scope['selectedKantorIds'],
            'areaOptions' => $scope['areaOptions'],
            'selectedArea' => $scope['selectedArea'],
            'clusterOptions' => $scope['clusterOptions'],
            'selectedClusters' => $scope['selectedClusters'],
            'totals' => $totals,
            'closing' => $closing,
            'area' => $area,
            'ringJarak' => $ringJarak,
            'sektor' => $sektor,
            'periode' => $periode,
            'funnel' => $funnel,
            'produk' => $produk,
            'cakupan' => $cakupan,
            'produkCakupan' => $produkCakupan,
            'totalHasilKunjungan' => array_sum($funnel),
        ]);
    }

    /**
     * Server-side kantor scope — same shape as PoiController::scopeIndexQuery,
     * now layered with an Area -> Cabang-Cluster -> Cabang drill-down
     * (2026-07-23, dashboard-only — no other screen has this hierarchy).
     * admin: unrestricted, optional ?area=/?cluster[]=/?kantor[]= narrows the
     * scope one level at a time. admin_final: always bounded to their own
     * kantor (user_kantor) FIRST, then the same drill-down narrows within
     * that owned set — any forged value outside what they own is silently
     * dropped, never trusted. sales: hard-locked to the single session active
     * kantor, none of area/cluster/kantor is even read (no picker renders).
     */
    private function resolveKantorScope(User $user, Request $request): array
    {
        if ($user->isAdmin()) {
            $allKantor = Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get();

            return $this->buildHierarchicalScope($request, $allKantor, 'Semua Kantor');
        }

        if ($user->isAdminFinal()) {
            $owned = $user->kantor()->orderBy('nama')->get();

            return $this->buildHierarchicalScope($request, $owned, 'Semua Kantor Saya');
        }

        $activeId = (int) session('active_kantor_id');

        return [
            'kantorIds' => [$activeId],
            'kantorOptions' => new Collection,
            'selectedKantorIds' => [$activeId],
            'label' => optional($user->kantor->firstWhere('id', $activeId))->nama ?? 'Kantor Saya',
            'areaOptions' => new Collection,
            'selectedArea' => null,
            'clusterOptions' => new Collection,
            'selectedClusters' => [],
        ];
    }

    /**
     * Narrows $allowedKantor (already role-scoped by the caller) through 3
     * optional levels, each computed from the REQUEST STATE of the level(s)
     * above it, not live client-side cascading — Area changing reloads the
     * page (plain <select onchange="submit">), so Cluster options always
     * reflect whichever Area is currently in the URL; Cluster/Cabang are
     * multi-select "pick several, then Terapkan" (same chip-picker component
     * as the existing kantor monitor), so narrowing by a cluster pick only
     * takes effect (and narrows the Cabang picker's own options) on the NEXT
     * page load, once Terapkan is pressed — an intentional two-step drill,
     * not a bug. Nothing selected at any level falls back to the full
     * $allowedKantor scope, same default as before this feature existed.
     */
    private function buildHierarchicalScope(Request $request, Collection $allowedKantor, string $allLabel): array
    {
        $areaOptions = $allowedKantor->pluck('area')->filter()->unique()->sort()->values();
        $selectedArea = $request->filled('area') && $areaOptions->contains($request->input('area'))
            ? $request->input('area')
            : null;

        $kantorInArea = $selectedArea !== null
            ? $allowedKantor->where('area', $selectedArea)->values()
            : $allowedKantor;

        $clusterOptions = $kantorInArea->pluck('cabang_cluster')->filter()->unique()->sort()->values();
        $selectedClusters = $this->parseMultiSelect($request, 'cluster', $clusterOptions->all());

        $kantorInCluster = $selectedClusters !== []
            ? $kantorInArea->whereIn('cabang_cluster', $selectedClusters)->values()
            : $kantorInArea;

        $selectedKantorIds = $this->parseSelectedKantorIds($request, $kantorInCluster->pluck('id')->all());

        return [
            'kantorIds' => $selectedKantorIds !== [] ? $selectedKantorIds : $kantorInCluster->pluck('id')->all(),
            'kantorOptions' => $kantorInCluster,
            'selectedKantorIds' => $selectedKantorIds,
            'label' => $this->buildKantorLabel($selectedKantorIds, $selectedClusters, $selectedArea, $allowedKantor, $allLabel),
            'areaOptions' => $areaOptions,
            'selectedArea' => $selectedArea,
            'clusterOptions' => $clusterOptions,
            'selectedClusters' => $selectedClusters,
        ];
    }

    /**
     * ?kantor= accepts either a single value (?kantor=5) or several
     * (?kantor[]=5&kantor[]=7) — normalized to an array either way. Ids
     * outside $allowedIds are dropped individually rather than voiding the
     * whole selection, so a forged id mixed in with legitimate ones doesn't
     * fall back to leaking the unscoped "all" view.
     */
    private function parseSelectedKantorIds(Request $request, array $allowedIds): array
    {
        $ids = collect(Arr::wrap($request->input('kantor', [])))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        return array_values(array_intersect($ids, $allowedIds));
    }

    /** Same shape as parseSelectedKantorIds() but for string values (Area/Cabang-Cluster names, not ids). */
    private function parseMultiSelect(Request $request, string $key, array $allowedValues): array
    {
        $values = collect(Arr::wrap($request->input($key, [])))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (string) $v)
            ->unique()
            ->values()
            ->all();

        return array_values(array_intersect($values, $allowedValues));
    }

    private function buildKantorLabel(array $selectedKantorIds, array $selectedClusters, ?string $selectedArea, Collection $options, string $allLabel): string
    {
        if ($selectedKantorIds !== []) {
            $names = $options->whereIn('id', $selectedKantorIds)->pluck('nama');

            return $names->count() > 1 ? $names->count().' Cabang Dipilih' : (string) $names->first();
        }

        if ($selectedClusters !== []) {
            return count($selectedClusters) > 1 ? count($selectedClusters).' Cabang-Cluster Dipilih' : $selectedClusters[0];
        }

        if ($selectedArea !== null) {
            return $selectedArea;
        }

        return $allLabel;
    }

    /**
     * Reads current-stock POI totals from dashboard_summary (never a live
     * COUNT on `poi`). One or several explicitly selected kantor sum their
     * own latest snapshot rows (summing a single row is a no-op, so this
     * also covers the old single-kantor case). admin's unscoped "ALL" view
     * reads the sentinel row PoiObserver already keeps in sync for every
     * kantor. admin_final's unscoped view sums the latest row *per owned
     * kantor* — deliberately not the global sentinel, which would pull in
     * every other kantor in the system too.
     */
    private function resolveTotals(User $user, array $kantorIds, bool $isFullyUnscoped): array
    {
        if (! $isFullyUnscoped) {
            // Covers a specific Cabang selection AND an Area/Cabang-Cluster
            // narrowing with no specific Cabang picked yet (2026-07-23) — in
            // both cases $kantorIds is already the narrowed set
            // (buildHierarchicalScope), so summing it directly is correct
            // either way. Only truly nothing-selected-at-any-level reaches
            // the sentinel/full-aggregate branch below.
            $row = $this->sumSummaryRows($kantorIds);
        } elseif ($user->isAdmin()) {
            $allId = Kantor::where('kode', Kantor::SENTINEL_ALL_KODE)->value('id');
            $row = $allId ? $this->latestSummaryRow($allId) : null;
        } else {
            // admin_final's owned set is small, not a hot path.
            $row = $this->sumSummaryRows($kantorIds);
        }

        $totalPoi = (int) ($row->total_poi ?? 0);
        $totalBni = (int) (($row->poi_non_merchant ?? 0) + ($row->poi_merchant ?? 0));
        $totalNon = (int) ($row->poi_bukan_nasabah ?? 0);

        return [
            'total_poi' => $totalPoi,
            'total_bni' => $totalBni,
            'total_non' => $totalNon,
            'persen_bni' => $totalPoi ? round($totalBni / $totalPoi * 100, 1) : 0,
            'persen_non' => $totalPoi ? round($totalNon / $totalPoi * 100, 1) : 0,
        ];
    }

    /**
     * The most recent snapshot row for one kantor, ordered by the actual
     * `tanggal` column — not `id` (insertion order), which a backdated
     * kunjungan can put out of sync with date order for the same kantor.
     */
    private function latestSummaryRow(int $kantorId): ?DashboardSummary
    {
        return DashboardSummary::where('kantor_id', $kantorId)
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Sums each kantor's own latest snapshot row (see latestSummaryRow — why
     * this is a per-kantor loop, not a MAX(id)/tuple-IN subquery). Summing a
     * single-kantor selection is a no-op, so this doubles as the plain
     * single-kantor path too.
     */
    private function sumSummaryRows(array $kantorIds): object
    {
        $rows = collect($kantorIds)->map(fn ($id) => $this->latestSummaryRow($id))->filter();

        return (object) [
            'total_poi' => $rows->sum('total_poi'),
            'poi_bukan_nasabah' => $rows->sum('poi_bukan_nasabah'),
            'poi_non_merchant' => $rows->sum('poi_non_merchant'),
            'poi_merchant' => $rows->sum('poi_merchant'),
        ];
    }

    /**
     * All 4 rings. All three variants (all/bni/non) divide by the same
     * all-status ring total — preserved from v1 as-is.
     */
    /**
     * Distance bands to print next to each ring on this page, or null when the
     * current scope can't have one.
     *
     * A ring means a different distance in a big city than anywhere else
     * (Kantor::ringLabel()), and the ring panels here aggregate every Cabang in
     * scope at once. That is only answerable when the scope is all one class —
     * filter down to Padang and "Ring 1" is 0 - 1 Km, filter to Painan and it
     * is 0 - 5 Km, but leave it on Semua Kantor and the same bar holds both.
     * Mixed
     * scopes return null and the view says so rather than picking one band and
     * mislabelling the other half of the bar.
     *
     * @return array<string, string>|null
     */
    private function ringJarakUntukScope(array $kantorIds): ?array
    {
        if ($kantorIds === []) {
            return null;
        }

        $kelas = Kantor::whereIn('id', $kantorIds)->distinct()->pluck('kota_besar');

        if ($kelas->count() !== 1) {
            return null;
        }

        return $kelas->first()
            ? Kantor::RING_JARAK_KOTA_BESAR
            : Kantor::RING_JARAK_KOTA_KECIL;
    }

    private function areaBreakdown(array $kantorIds): array
    {
        $base = fn () => Poi::query()
            ->whereIn('kantor_id', $kantorIds)
            ->where('status', 'aktif')
            ->whereIn('area', self::RING_LEVELS);

        $all = $base()->select('area', DB::raw('count(*) as total'))->groupBy('area')->pluck('total', 'area');
        $totalRings = (int) $all->sum();

        $bni = $base()->whereIn('status_mitra', self::BNI_STATUSES)
            ->select('area', DB::raw('count(*) as total'))->groupBy('area')->pluck('total', 'area');

        $non = $base()->where('status_mitra', self::BUKAN_NASABAH)
            ->select('area', DB::raw('count(*) as total'))->groupBy('area')->pluck('total', 'area');

        $build = function (Collection $counts) use ($totalRings) {
            $out = [];
            foreach (self::RING_LEVELS as $ring) {
                $total = (int) ($counts[$ring] ?? 0);
                $out[$ring] = [
                    'label' => $ring,
                    'total' => $total,
                    'persen' => $totalRings > 0 ? round($total / $totalRings * 100, 1) : 0,
                ];
            }

            return $out;
        };

        return ['all' => $build($all), 'bni' => $build($bni), 'non' => $build($non)];
    }

    /**
     * Feeds the two "Top Kategori" panels. Each side is ranked by ITS OWN
     * count — top 5 sektor, and within each, top 3 sub_sektor.
     *
     * Until 2026-07-29 both panels reused a single ranking taken from the BNI
     * counts (a v1 quirk that had been preserved deliberately). That made the
     * Non-BNI panel wrong rather than merely oddly ordered: with production
     * data it listed Education first when Food & Beverage was more than twice
     * its size, and showed UNIVERSITY (890) as a top-3 sub of Education while
     * PRESCHOOL (933) — genuinely bigger on that side — never appeared at all.
     * The donut's dark-to-light ramp follows list order, so it disagreed with
     * the slice sizes too.
     *
     * Sub rows are fetched once per sektor and sorted twice rather than
     * queried per side: the two lists overlap heavily (usually the same five
     * sektor in a different order), so this stays at roughly the previous
     * query count instead of doubling it.
     *
     * Also returns each side's grand total across ALL sektor (not just the
     * five plotted) — the donut centre reports the BNI/Non-BNI split of the
     * whole POI population.
     */
    private function sektorBreakdown(array $kantorIds): array
    {
        $sektorRows = Poi::query()
            ->whereIn('kantor_id', $kantorIds)
            ->where('status', 'aktif')
            ->whereNotNull('sektor')
            ->select('sektor')
            ->selectRaw('SUM(CASE WHEN status_mitra IN (?, ?) THEN 1 ELSE 0 END) as bni', self::BNI_STATUSES)
            ->selectRaw('COUNT(*) as total')
            ->groupBy('sektor')
            ->get()
            ->map(fn ($row) => [
                'sektor' => $row->sektor,
                'bni' => (int) $row->bni,
                'non' => (int) $row->total - (int) $row->bni,
                'total' => (int) $row->total,
            ]);

        $totalBni = $sektorRows->sum('bni');
        $totalNon = $sektorRows->sum('non');

        $topBni = $sektorRows->sortByDesc('bni')->take(5)->values();
        $topNon = $sektorRows->sortByDesc('non')->take(5)->values();

        $subsBySektor = [];
        foreach ($topBni->pluck('sektor')->merge($topNon->pluck('sektor'))->unique() as $sektor) {
            $subsBySektor[$sektor] = Poi::query()
                ->whereIn('kantor_id', $kantorIds)
                ->where('status', 'aktif')
                ->where('sektor', $sektor)
                ->whereNotNull('sub_sektor')
                ->where('sub_sektor', '!=', '')
                ->select('sub_sektor')
                ->selectRaw('SUM(CASE WHEN status_mitra IN (?, ?) THEN 1 ELSE 0 END) as bni', self::BNI_STATUSES)
                ->selectRaw('COUNT(*) as total')
                ->groupBy('sub_sektor')
                ->get()
                ->map(fn ($row) => [
                    'sub_sektor' => $row->sub_sektor,
                    'bni' => (int) $row->bni,
                    'non' => (int) $row->total - (int) $row->bni,
                    'total' => (int) $row->total,
                ]);
        }

        $build = fn ($rows, string $side) => $rows->map(fn ($row) => [
            'sektor' => $row['sektor'],
            'total' => $row[$side],
            // Share of THIS sektor that is BNI (or not) — unchanged meaning.
            'persen' => $row['total'] ? round($row[$side] / $row['total'] * 100, 2) : 0,
            'subs' => collect($subsBySektor[$row['sektor']] ?? [])
                ->sortByDesc($side)
                ->take(3)
                ->map(fn ($sub) => [
                    'sub_sektor' => $sub['sub_sektor'],
                    'total' => $sub[$side],
                    'persen' => $sub['total'] ? round($sub[$side] / $sub['total'] * 100, 2) : 0,
                ])
                ->values()
                ->all(),
        ])->all();

        $grandTotal = $totalBni + $totalNon;

        return [
            'bni' => $build($topBni, 'bni'),
            'non' => $build($topNon, 'non'),
            'total_bni' => $totalBni,
            'total_non' => $totalNon,
            'persen_bni' => $grandTotal ? round($totalBni / $grandTotal * 100, 1) : 0,
            'persen_non' => $grandTotal ? round($totalNon / $grandTotal * 100, 1) : 0,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * "day"=today, "week"=current calendar month, "month"=rolling 45 days.
     * Preserved exactly from v1 — not a mislabeling bug, the real system's
     * actual semantics.
     */
    private function periodeRange(string $periode): array
    {
        $now = Carbon::now();

        return match ($periode) {
            'week' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'month' => [$now->copy()->subDays(45)->startOfDay(), $now->copy()->endOfDay()],
            // "All" = no period filter at all. Expressed as a window wide
            // enough to hold every kunjungan rather than by making the four
            // whereBetween() calls below conditional — same result, and the
            // upper bound is deliberately far in the future so a backdated or
            // mistyped future date still shows up here instead of silently
            // vanishing from the one view that claims to show everything.
            'all' => [Carbon::create(2000, 1, 1)->startOfDay(), Carbon::create(2100, 12, 31)->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    private function ringkasanHasil(array $kantorIds, Carbon $start, Carbon $end): array
    {
        $counts = Kunjungan::query()
            ->whereHas('poi', fn ($q) => $q->whereIn('kantor_id', $kantorIds))
            ->whereBetween('tanggal_kunjungan', [$start->toDateString(), $end->toDateString()])
            ->select('hasil', DB::raw('count(*) as total'))
            ->groupBy('hasil')
            ->pluck('total', 'hasil');

        $out = [];
        foreach (Kunjungan::HASIL_OPTIONS as $hasil) {
            $out[$hasil] = (int) ($counts[$hasil] ?? 0);
        }

        return $out;
    }

    /**
     * A kunjungan can offer several products at once (kunjungan_produk pivot,
     * not a single column — see Kunjungan::PRODUK_OPTIONS docblock), so this
     * counts distinct closing kunjungan per product, joined through the pivot.
     */
    private function produkClosing(array $kantorIds, Carbon $start, Carbon $end): array
    {
        $counts = KunjunganProduk::query()
            ->join('kunjungan', 'kunjungan.id', '=', 'kunjungan_produk.kunjungan_id')
            ->join('poi', 'poi.id', '=', 'kunjungan.poi_id')
            ->whereIn('poi.kantor_id', $kantorIds)
            ->where('kunjungan.hasil', Kunjungan::HASIL_CLOSING)
            ->whereBetween('kunjungan.tanggal_kunjungan', [$start->toDateString(), $end->toDateString()])
            ->select('kunjungan_produk.produk', DB::raw('count(*) as total'))
            ->groupBy('kunjungan_produk.produk')
            ->pluck('total', 'produk');

        $out = [];
        foreach (Kunjungan::PRODUK_OPTIONS as $produk) {
            $out[$produk] = (int) ($counts[$produk] ?? 0);
        }

        return $out;
    }

    /**
     * Per-Cabang contribution for the "Kontribusi Cabang" panel.
     *
     * Driven from the Cabang in scope, NOT from the kunjungan table. The bar
     * chart this replaces started at kunjungan and joined kantor, so a Cabang
     * with no visit in the period simply had no row and vanished from the
     * chart — which is why picking an Area still showed only a handful of its
     * Cabang. A Cabang that contributed nothing is the single most useful
     * thing this panel can show, so it has to be a zero, never an absence.
     *
     * Grouped by Area, and the thresholds are relative to the busiest Cabang
     * in scope rather than fixed: the same panel has to stay readable whether
     * the period is one day (counts in single digits) or All (thousands).
     *
     * @return array{areas: array<int, array{nama: string, cabang: array<int, array{nama: string, total: int, closing: int, level: int}>, sudah: int, jumlah: int}>, sudah: int, jumlah: int, persen: float, maks: int, ambang: array<int, array{min: int, max: int}>}
     */
    /**
     * Products recorded on visits in scope, split by whether the visit closed.
     *
     * The existing "Produk BNI - Closing" panel answers the closing half only.
     * Seeing the same products on visits that have NOT closed yet is what says
     * where the pipeline actually sits — a product heavy on non-closing is a
     * queue, not a loss.
     *
     * Only products with a count are returned, sorted busiest first: the
     * option list runs to twenty-odd entries and a wall of zeros buries the
     * few that matter.
     *
     * @return array{closing: array<string, int>, non_closing: array<string, int>, total_closing: int, total_non_closing: int}
     */
    private function produkCakupan(array $kantorIds, Carbon $start, Carbon $end): array
    {
        $kosong = ['closing' => [], 'non_closing' => [], 'total_closing' => 0, 'total_non_closing' => 0];

        if ($kantorIds === []) {
            return $kosong;
        }

        $rows = KunjunganProduk::query()
            ->join('kunjungan', 'kunjungan.id', '=', 'kunjungan_produk.kunjungan_id')
            ->join('poi', 'poi.id', '=', 'kunjungan.poi_id')
            ->whereIn('poi.kantor_id', $kantorIds)
            ->whereBetween('kunjungan.tanggal_kunjungan', [$start->toDateString(), $end->toDateString()])
            ->groupBy('kunjungan_produk.produk', 'kunjungan.hasil')
            ->select('kunjungan_produk.produk', 'kunjungan.hasil')
            ->selectRaw('COUNT(*) as n')
            ->get();

        $closing = [];
        $non = [];
        foreach ($rows as $r) {
            if ($r->hasil === Kunjungan::HASIL_CLOSING) {
                $closing[$r->produk] = ($closing[$r->produk] ?? 0) + (int) $r->n;
            } else {
                $non[$r->produk] = ($non[$r->produk] ?? 0) + (int) $r->n;
            }
        }

        arsort($closing);
        arsort($non);

        return [
            'closing' => $closing,
            'non_closing' => $non,
            'total_closing' => array_sum($closing),
            'total_non_closing' => array_sum($non),
        ];
    }

    private function cakupanCabang(array $kantorIds, Carbon $start, Carbon $end): array
    {
        $kosong = [
            'areas' => [], 'sudah' => 0, 'jumlah' => 0, 'persen' => 0.0, 'maks' => 0, 'ambang' => [],
            'tahap' => array_fill_keys(Kunjungan::HASIL_OPTIONS, 0), 'total' => 0, 'closing' => 0, 'belum_closing' => 0,
        ];

        if ($kantorIds === []) {
            return $kosong;
        }

        $kantor = Kantor::whereIn('id', $kantorIds)
            ->where('kode', '!=', Kantor::SENTINEL_ALL_KODE)
            ->orderBy('nama')
            ->get(['id', 'nama', 'area']);

        if ($kantor->isEmpty()) {
            return $kosong;
        }

        // Per Cabang PER TAHAP, not just a total: the panel has to answer
        // "how is the follow-up going here", and a bare count can't separate a
        // Cabang sitting on 12 Belum Berminat from one with 12 Collecting
        // Dokumen. One grouped query for the whole scope, not one per Cabang.
        $perTahap = Kunjungan::query()
            ->join('poi', 'poi.id', '=', 'kunjungan.poi_id')
            ->whereIn('poi.kantor_id', $kantorIds)
            ->whereBetween('kunjungan.tanggal_kunjungan', [$start->toDateString(), $end->toDateString()])
            ->groupBy('poi.kantor_id', 'kunjungan.hasil')
            ->select('poi.kantor_id', 'kunjungan.hasil')
            ->selectRaw('COUNT(*) as n')
            ->get()
            ->groupBy('kantor_id');

        $hitung = $perTahap->map(function ($baris) {
            $tahap = [];
            foreach (Kunjungan::HASIL_OPTIONS as $hasil) {
                $tahap[$hasil] = 0;
            }
            foreach ($baris as $r) {
                if (array_key_exists($r->hasil, $tahap)) {
                    $tahap[$r->hasil] = (int) $r->n;
                }
            }

            $total = array_sum($tahap);
            $closing = $tahap[Kunjungan::HASIL_CLOSING];

            return [
                'tahap' => $tahap,
                'total' => $total,
                'closing' => $closing,
                'belum_closing' => $total - $closing,
            ];
        });

        $maks = (int) collect($hitung)->max('total');

        // Three bands over 1..maks, so "busiest" always reads as the darkest
        // tile whatever the period. Level 0 is reserved for a true zero and is
        // never just "the lowest band".
        $ambang = [];
        if ($maks > 0) {
            $lebar = max(1, (int) ceil($maks / 3));
            for ($i = 0; $i < 3; $i++) {
                $ambang[$i + 1] = [
                    'min' => $i * $lebar + 1,
                    'max' => min($maks, ($i + 1) * $lebar),
                ];
            }
        }

        $level = function (int $total) use ($ambang): int {
            if ($total <= 0) {
                return 0;
            }
            foreach ($ambang as $tingkat => $batas) {
                if ($total <= $batas['max']) {
                    return $tingkat;
                }
            }

            return 3;
        };

        $tahapKosong = array_fill_keys(Kunjungan::HASIL_OPTIONS, 0);

        $areas = [];
        $sudah = 0;
        $tahapTotal = $tahapKosong;
        foreach ($kantor as $k) {
            $baris = $hitung[$k->id] ?? null;
            $total = (int) ($baris['total'] ?? 0);
            if ($total > 0) {
                $sudah++;
            }

            $tahap = $baris['tahap'] ?? $tahapKosong;
            foreach ($tahap as $nama => $n) {
                $tahapTotal[$nama] += $n;
            }

            $namaArea = $k->area ?: 'Tanpa Area';
            $areas[$namaArea]['nama'] = $namaArea;
            $areas[$namaArea]['cabang'][] = [
                'nama' => $k->nama,
                'total' => $total,
                'closing' => (int) ($baris['closing'] ?? 0),
                'belum_closing' => (int) ($baris['belum_closing'] ?? 0),
                'tahap' => $tahap,
                'level' => $level($total),
            ];
        }

        ksort($areas);
        foreach ($areas as $nama => $area) {
            $areas[$nama]['sudah'] = count(array_filter($area['cabang'], fn ($c) => $c['total'] > 0));
            $areas[$nama]['jumlah'] = count($area['cabang']);
        }

        $totalSemua = array_sum($tahapTotal);

        return [
            'areas' => array_values($areas),
            'sudah' => $sudah,
            'jumlah' => $kantor->count(),
            'persen' => $kantor->count() > 0 ? round($sudah / $kantor->count() * 100, 1) : 0.0,
            'maks' => $maks,
            'ambang' => $ambang,
            'tahap' => $tahapTotal,
            'total' => $totalSemua,
            'closing' => $tahapTotal[Kunjungan::HASIL_CLOSING],
            'belum_closing' => $totalSemua - $tahapTotal[Kunjungan::HASIL_CLOSING],
        ];
    }

    private function closingStats(array $kantorIds, Carbon $start, Carbon $end, int $totalBni, int $totalNon): array
    {
        $baseQuery = fn () => Kunjungan::query()
            ->whereHas('poi', fn ($q) => $q->whereIn('kantor_id', $kantorIds))
            ->whereBetween('tanggal_kunjungan', [$start->toDateString(), $end->toDateString()]);

        $totalClosing = (clone $baseQuery())->where('hasil', Kunjungan::HASIL_CLOSING)->count();
        $totalPoiPeriode = $baseQuery()->count();

        return [
            'total_closing' => $totalClosing,
            'persen_closing_bni' => $totalBni > 0 ? round($totalClosing / $totalBni * 100, 2) : 0,
            'persen_akuisisi_vs_non' => $totalNon > 0 ? round($totalClosing / $totalNon * 100, 2) : 0,
            'persen_closing_poi' => $totalPoiPeriode > 0 ? round($totalClosing / $totalPoiPeriode * 100, 2) : 0,
        ];
    }
}
