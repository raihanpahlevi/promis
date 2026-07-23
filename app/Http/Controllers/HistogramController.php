<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NarrowsKantorByAreaCluster;
use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\KunjunganProduk;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "Dashboard Admin" / Histogram (admin + admin_final only) — rebuilt from the real v1
 * dashboard_admin.php. Distinct from the main Dashboard: this one is period-filterable
 * (custom date range, not just day/week/month presets). Logic preserved as-is,
 * including the specific way closing rate is computed (see produkTotals() docblock) —
 * only the look changed.
 *
 * v1 also had a "Sebaran POI" Leaflet map here — removed for now (POI aren't geocoded
 * yet, so it just rendered empty; geocoding is deferred, see Tahap 6 discussion). Bring
 * it back once `poi.latitude`/`longitude` are actually populated — the map query it
 * used was kantor-scoped correctly (fixing a real leak in v1's own version, where
 * selecting "ALL" dropped the kantor filter entirely), worth reusing that logic rather
 * than re-deriving it.
 */
class HistogramController extends Controller
{
    use NarrowsKantorByAreaCluster;

    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $user = $request->user();
        $scope = $this->resolveKantorScope($user, $request);

        $dari = $request->filled('dari') ? $request->input('dari') : Carbon::now()->startOfMonth()->toDateString();
        $sampai = $request->filled('sampai') ? $request->input('sampai') : Carbon::now()->toDateString();

        $histogram = $this->histogram($scope['kantorIds'], $dari, $sampai);
        $produk = $this->produkTotals($scope['kantorIds'], $dari, $sampai);
        $detail = $this->detailAkuisisi($scope['kantorIds'], $dari, $sampai);

        return view('histogram.index', [
            'kantorOptions' => $scope['kantorOptions'],
            'selectedKantorId' => $scope['selectedKantorId'],
            'kantorLocked' => $scope['locked'],
            'kantorAreaOptions' => $scope['areaOptions'],
            'selectedKantorArea' => $scope['selectedArea'],
            'kantorClusterOptions' => $scope['clusterOptions'],
            'selectedKantorCluster' => $scope['selectedCluster'],
            'dari' => $dari,
            'sampai' => $sampai,
            'histogram' => $histogram,
            'produkAll' => $produk['all'],
            'produkClosing' => $produk['closing'],
            'closingRate' => $produk['closingRate'],
            'detail' => $detail,
        ]);
    }

    /**
     * admin: free choice, defaults to every kantor (query param optional). admin_final:
     * if they own exactly one kantor, the filter is locked to it (matches v1 — the
     * select is rendered `disabled` since there's no real choice to make, and Area/
     * Cluster don't render either — nothing to narrow); if they own several, defaults
     * to all of them with an optional narrowing filter, same pattern as the main
     * Dashboard. A forged kantor id outside their (now Area/Cluster-narrowed)
     * ownership is ignored.
     */
    private function resolveKantorScope(User $user, Request $request): array
    {
        if ($user->isAdmin()) {
            $allKantor = Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get();

            return $this->buildHistogramScope($request, $allKantor, false);
        }

        $owned = $user->kantor()->orderBy('nama')->get();

        if ($owned->count() === 1) {
            return [
                'kantorIds' => [$owned->first()->id],
                'kantorOptions' => $owned,
                'selectedKantorId' => $owned->first()->id,
                'locked' => true,
                'areaOptions' => new Collection(),
                'selectedArea' => null,
                'clusterOptions' => new Collection(),
                'selectedCluster' => null,
            ];
        }

        return $this->buildHistogramScope($request, $owned, false);
    }

    private function buildHistogramScope(Request $request, Collection $allowedKantor, bool $locked): array
    {
        $narrowed = $this->narrowKantorByAreaCluster($request, $allowedKantor);
        $narrowedIds = $narrowed['kantorOptions']->pluck('id')->all();

        $selected = $request->filled('kantor') && in_array((int) $request->input('kantor'), $narrowedIds, true)
            ? (int) $request->input('kantor')
            : null;

        return [
            'kantorIds' => $selected !== null ? [$selected] : $narrowedIds,
            'kantorOptions' => $narrowed['kantorOptions'],
            'selectedKantorId' => $selected,
            'locked' => $locked,
            'areaOptions' => $narrowed['areaOptions'],
            'selectedArea' => $narrowed['selectedArea'],
            'clusterOptions' => $narrowed['clusterOptions'],
            'selectedCluster' => $narrowed['selectedCluster'],
        ];
    }

    /**
     * Per-day distinct-POI counts (not raw visit counts — matches v1's
     * COUNT(DISTINCT ... poi_id): a POI visited twice the same day only counts once).
     */
    private function histogram(array $kantorIds, string $dari, string $sampai): array
    {
        $rows = Kunjungan::query()
            ->join('poi', 'poi.id', '=', 'kunjungan.poi_id')
            ->whereIn('poi.kantor_id', $kantorIds)
            ->whereBetween('kunjungan.tanggal_kunjungan', [$dari, $sampai])
            ->select(DB::raw('DATE(kunjungan.tanggal_kunjungan) as tgl'))
            ->selectRaw("COUNT(DISTINCT CASE WHEN kunjungan.hasil = ? THEN kunjungan.poi_id END) as closing", [Kunjungan::HASIL_CLOSING])
            ->selectRaw("COUNT(DISTINCT CASE WHEN kunjungan.hasil <> ? THEN kunjungan.poi_id END) as non_closing", [Kunjungan::HASIL_CLOSING])
            ->groupBy(DB::raw('DATE(kunjungan.tanggal_kunjungan)'))
            ->orderBy('tgl')
            ->get();

        return [
            'labels' => $rows->map(fn ($r) => Carbon::parse($r->tgl)->translatedFormat('d M'))->all(),
            'closing' => $rows->pluck('closing')->map(fn ($v) => (int) $v)->all(),
            'non_closing' => $rows->pluck('non_closing')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    /**
     * Product totals for the two pie charts, plus a closing rate computed from the
     * SAME totals (total product-mentions-in-closing-visits / total product-mentions
     * overall) — deliberately not a POI- or visit-level rate, so it always agrees with
     * what the two pie charts show. Preserved from v1 exactly (its own comment there
     * calls out keeping this "sinkron dengan pie").
     */
    private function produkTotals(array $kantorIds, string $dari, string $sampai): array
    {
        $rows = KunjunganProduk::query()
            ->join('kunjungan', 'kunjungan.id', '=', 'kunjungan_produk.kunjungan_id')
            ->join('poi', 'poi.id', '=', 'kunjungan.poi_id')
            ->whereIn('poi.kantor_id', $kantorIds)
            ->whereBetween('kunjungan.tanggal_kunjungan', [$dari, $sampai])
            ->select('kunjungan_produk.produk')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN kunjungan.hasil = ? THEN 1 ELSE 0 END) as closing", [Kunjungan::HASIL_CLOSING])
            ->groupBy('kunjungan_produk.produk')
            ->get();

        $all = [];
        $closing = [];
        foreach ($rows as $row) {
            $all[$row->produk] = (int) $row->total;
            if ((int) $row->closing > 0) {
                $closing[$row->produk] = (int) $row->closing;
            }
        }

        $totalAll = array_sum($all);
        $totalClosing = array_sum($closing);

        return [
            'all' => $all,
            'closing' => $closing,
            'closingRate' => $totalAll > 0 ? round($totalClosing / $totalAll * 100, 1) : 0,
        ];
    }

    /**
     * Closing-only visits in range, paginated — v1 dumped this unbounded into a
     * scrolling div, which doesn't hold up at PRD's target scale.
     */
    private function detailAkuisisi(array $kantorIds, string $dari, string $sampai)
    {
        return Kunjungan::query()
            ->with(['poi.kantor', 'produkList'])
            ->whereHas('poi', fn ($q) => $q->whereIn('kantor_id', $kantorIds))
            ->where('hasil', Kunjungan::HASIL_CLOSING)
            ->whereBetween('tanggal_kunjungan', [$dari, $sampai])
            ->orderByDesc('tanggal_kunjungan')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }
}
