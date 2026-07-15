<?php

namespace App\Http\Controllers;

use App\Models\DashboardSummary;
use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\KunjunganProduk;
use App\Models\Poi;
use App\Models\User;
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
 *  - Top Area only ever shows the 3 closest rings (Ring 1-3, excluding
 *    Ring 4 — the farthest/lowest-priority bucket), matching v1's
 *    R1/R2/R3-only grouping. Area labels themselves (Poi::AREA_OPTIONS) are
 *    "Ring N (jarak km)" strings, product decision 2026-07-14 — not the
 *    schema's old bare "RING 1".."RING 5".
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
    private const RING_LEVELS = ['Ring 1 (0 - 1 Km)', 'Ring 2 (>1 - 3 Km)', 'Ring 3 (>3 - 5 Km)'];

    private const BNI_STATUSES = ['Nasabah Non Merchant BNI', 'Nasabah Merchant BNI'];

    private const BUKAN_NASABAH = 'Bukan Nasabah BNI';

    public function index(Request $request): View
    {
        $user = $request->user();
        $scope = $this->resolveKantorScope($user, $request);
        $kantorIds = $scope['kantorIds'];

        $totals = $this->resolveTotals($user, $kantorIds, $scope['selectedKantorIds']);
        $area = $this->areaBreakdown($kantorIds);
        $sektor = $this->sektorBreakdown($kantorIds);

        $periode = in_array($request->input('periode'), ['day', 'week', 'month'], true)
            ? $request->input('periode')
            : 'day';
        [$start, $end] = $this->periodeRange($periode);

        $funnel = $this->ringkasanHasil($kantorIds, $start, $end);
        $produk = $this->produkClosing($kantorIds, $start, $end);
        $chart = $this->chartPerKantor($kantorIds, $start, $end);
        $closing = $this->closingStats($kantorIds, $start, $end, $totals['total_bni'], $totals['total_non']);

        return view('dashboard', [
            'kantorLabel' => $scope['label'],
            'kantorOptions' => $scope['kantorOptions'],
            'selectedKantorIds' => $scope['selectedKantorIds'],
            'totals' => $totals,
            'closing' => $closing,
            'area' => $area,
            'sektor' => $sektor,
            'periode' => $periode,
            'funnel' => $funnel,
            'produk' => $produk,
            'chart' => $chart,
            'totalHasilKunjungan' => array_sum($funnel),
        ]);
    }

    /**
     * Server-side kantor scope — same shape as PoiController::scopeIndexQuery.
     * admin: unrestricted, optional ?kantor[]= narrows to one or several for
     * combined monitoring. admin_final: always bounded to their own kantor
     * (user_kantor), optional ?kantor[]= narrows to a subset of their own —
     * any forged id outside that set is silently dropped from the selection,
     * never trusted. sales: hard-locked to the single session active kantor,
     * ?kantor is not even read.
     */
    private function resolveKantorScope(User $user, Request $request): array
    {
        if ($user->isAdmin()) {
            $allKantor = Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get();
            $selectedIds = $this->parseSelectedKantorIds($request, $allKantor->pluck('id')->all());

            return [
                'kantorIds' => $selectedIds !== [] ? $selectedIds : $allKantor->pluck('id')->all(),
                'kantorOptions' => $allKantor,
                'selectedKantorIds' => $selectedIds,
                'label' => $this->buildKantorLabel($selectedIds, $allKantor, 'Semua Kantor'),
            ];
        }

        if ($user->isAdminFinal()) {
            $owned = $user->kantor()->orderBy('nama')->get();
            $ownedIds = $owned->pluck('id')->all();
            $selectedIds = $this->parseSelectedKantorIds($request, $ownedIds);

            return [
                'kantorIds' => $selectedIds !== [] ? $selectedIds : $ownedIds,
                'kantorOptions' => $owned,
                'selectedKantorIds' => $selectedIds,
                'label' => $this->buildKantorLabel($selectedIds, $owned, 'Semua Kantor Saya'),
            ];
        }

        $activeId = (int) session('active_kantor_id');

        return [
            'kantorIds' => [$activeId],
            'kantorOptions' => new Collection(),
            'selectedKantorIds' => [$activeId],
            'label' => optional($user->kantor->firstWhere('id', $activeId))->nama ?? 'Kantor Saya',
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

    private function buildKantorLabel(array $selectedIds, Collection $options, string $allLabel): string
    {
        if ($selectedIds === []) {
            return $allLabel;
        }

        $names = $options->whereIn('id', $selectedIds)->pluck('nama');

        return $names->count() > 1 ? $names->count().' Kantor Dipilih' : (string) $names->first();
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
    private function resolveTotals(User $user, array $kantorIds, array $selectedKantorIds): array
    {
        if ($selectedKantorIds !== []) {
            $row = $this->sumSummaryRows($selectedKantorIds);
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
     * Ring 1-3 only (see class docblock). All three variants (all/bni/non)
     * divide by the same all-status Ring 1-3 total — preserved from v1 as-is.
     */
    private function areaBreakdown(array $kantorIds): array
    {
        $base = fn () => Poi::query()
            ->whereIn('kantor_id', $kantorIds)
            ->where('status', 'aktif')
            ->whereIn('area', self::RING_LEVELS);

        $all = $base()->select('area', DB::raw('count(*) as total'))->groupBy('area')->pluck('total', 'area');
        $totalR123 = (int) $all->sum();

        $bni = $base()->whereIn('status_mitra', self::BNI_STATUSES)
            ->select('area', DB::raw('count(*) as total'))->groupBy('area')->pluck('total', 'area');

        $non = $base()->where('status_mitra', self::BUKAN_NASABAH)
            ->select('area', DB::raw('count(*) as total'))->groupBy('area')->pluck('total', 'area');

        $build = function (Collection $counts) use ($totalR123) {
            $out = [];
            foreach (self::RING_LEVELS as $ring) {
                $total = (int) ($counts[$ring] ?? 0);
                $out[$ring] = [
                    'label' => $ring,
                    'total' => $total,
                    'persen' => $totalR123 > 0 ? round($total / $totalR123 * 100, 1) : 0,
                ];
            }

            return $out;
        };

        return ['all' => $build($all), 'bni' => $build($bni), 'non' => $build($non)];
    }

    /**
     * Top 5 sektor ranked by BNI count (both the BNI and Non-BNI panels show
     * this SAME ranking — v1 quirk, preserved). Top 3 sub_sektor per sektor,
     * same ranking rule.
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
            ->orderByDesc('bni')
            ->limit(5)
            ->get();

        $bniList = [];
        $nonList = [];

        foreach ($sektorRows as $row) {
            $bniCount = (int) $row->bni;
            $totalSektor = (int) $row->total;
            $nonCount = $totalSektor - $bniCount;

            $subRows = Poi::query()
                ->whereIn('kantor_id', $kantorIds)
                ->where('status', 'aktif')
                ->where('sektor', $row->sektor)
                ->whereNotNull('sub_sektor')
                ->where('sub_sektor', '!=', '')
                ->select('sub_sektor')
                ->selectRaw('SUM(CASE WHEN status_mitra IN (?, ?) THEN 1 ELSE 0 END) as bni', self::BNI_STATUSES)
                ->selectRaw('COUNT(*) as total')
                ->groupBy('sub_sektor')
                ->orderByDesc('bni')
                ->orderByDesc('total')
                ->limit(3)
                ->get();

            $bniSubs = [];
            $nonSubs = [];

            foreach ($subRows as $sub) {
                $subBni = (int) $sub->bni;
                $subTotal = (int) $sub->total;
                $subNon = $subTotal - $subBni;

                $bniSubs[] = [
                    'sub_sektor' => $sub->sub_sektor,
                    'total' => $subBni,
                    'persen' => $subTotal ? round($subBni / $subTotal * 100, 2) : 0,
                ];
                $nonSubs[] = [
                    'sub_sektor' => $sub->sub_sektor,
                    'total' => $subNon,
                    'persen' => $subTotal ? round($subNon / $subTotal * 100, 2) : 0,
                ];
            }

            $bniList[] = [
                'sektor' => $row->sektor,
                'total' => $bniCount,
                'persen' => $totalSektor ? round($bniCount / $totalSektor * 100, 2) : 0,
                'subs' => $bniSubs,
            ];
            $nonList[] = [
                'sektor' => $row->sektor,
                'total' => $nonCount,
                'persen' => $totalSektor ? round($nonCount / $totalSektor * 100, 2) : 0,
                'subs' => $nonSubs,
            ];
        }

        return ['bni' => $bniList, 'non' => $nonList];
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

    private function chartPerKantor(array $kantorIds, Carbon $start, Carbon $end): array
    {
        if (empty($kantorIds)) {
            return ['labels' => [], 'closing' => [], 'non_closing' => []];
        }

        $rows = Kunjungan::query()
            ->join('poi', 'poi.id', '=', 'kunjungan.poi_id')
            ->join('kantor', 'kantor.id', '=', 'poi.kantor_id')
            ->whereIn('poi.kantor_id', $kantorIds)
            ->whereBetween('kunjungan.tanggal_kunjungan', [$start->toDateString(), $end->toDateString()])
            ->select('kantor.nama as kantor_nama')
            ->selectRaw('SUM(CASE WHEN kunjungan.hasil = ? THEN 1 ELSE 0 END) as closing', [Kunjungan::HASIL_CLOSING])
            ->selectRaw('SUM(CASE WHEN kunjungan.hasil <> ? THEN 1 ELSE 0 END) as non_closing', [Kunjungan::HASIL_CLOSING])
            ->groupBy('kantor.nama')
            ->orderBy('kantor.nama')
            ->get();

        return [
            'labels' => $rows->pluck('kantor_nama')->all(),
            'closing' => $rows->pluck('closing')->map(fn ($v) => (int) $v)->all(),
            'non_closing' => $rows->pluck('non_closing')->map(fn ($v) => (int) $v)->all(),
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
