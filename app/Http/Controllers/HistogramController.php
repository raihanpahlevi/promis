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

    /**
     * The three produk histograms, in display order. Partitions
     * Kunjungan::PRODUK_OPTIONS exactly — 3 + 6 + 9 = 18, nothing shared and
     * nothing left out. Verified by HistogramTest; adding a produk to the model
     * without placing it in a group fails that test rather than quietly
     * dropping it off this page.
     */
    private const PRODUK_GRUP = [
        'DPK' => ['Tabungan', 'Giro', 'Deposito'],
        'LOAN' => ['Kredit SME', 'BWU', 'KUR', 'BNI Griya', 'BNI Fleksi', 'Kartu Kredit'],
        'Transaksional & Lainnya' => [
            'EDC', 'QRIS', 'BNI Direct', 'Trade Finance', 'Garansi Bank',
            'AGEN46', 'Payroll', 'BIONS Sekuritas', 'Wondr by BNI',
        ],
    ];

    /**
     * Shorter axis labels — the stored names are what the client calls them in
     * the source workbook, and the full ones ("BIONS Sekuritas") crowd the axis
     * once nine bars share a row.
     */
    private const PRODUK_LABEL = [
        'Kredit SME' => 'SME',
        'BNI Griya' => 'Griya',
        'BNI Fleksi' => 'Fleksi',
        'AGEN46' => 'Agen46',
        'BIONS Sekuritas' => 'Sekuritas',
        'Wondr by BNI' => 'Wondr',
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        $scope = $this->resolveKantorScope($user, $request);

        $dari = $request->filled('dari') ? $request->input('dari') : Carbon::now()->startOfMonth()->toDateString();
        $sampai = $request->filled('sampai') ? $request->input('sampai') : Carbon::now()->toDateString();

        $histogram = $this->histogram($scope['kantorIds'], $dari, $sampai);
        $produkGrup = $this->produkPerGrup($scope['kantorIds'], $dari, $sampai);

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
            'produkGrup' => $produkGrup,
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
     * Feeds the three produk histograms (2026-07-30 rebuild, replacing the two
     * donut charts). Each group gets one bar pair per produk: Ditawarkan (every
     * kunjungan_produk row in range, whatever the visit's hasil) and Closing
     * (only rows on a Closing visit) — the shape the client's example workbook
     * defines.
     */
    private function produkPerGrup(array $kantorIds, string $dari, string $sampai): array
    {
        $rows = KunjunganProduk::query()
            ->join('kunjungan', 'kunjungan.id', '=', 'kunjungan_produk.kunjungan_id')
            ->join('poi', 'poi.id', '=', 'kunjungan.poi_id')
            ->whereIn('poi.kantor_id', $kantorIds)
            ->whereBetween('kunjungan.tanggal_kunjungan', [$dari, $sampai])
            ->select('kunjungan_produk.produk')
            ->selectRaw('COUNT(*) as ditawarkan')
            ->selectRaw('SUM(CASE WHEN kunjungan.hasil = ? THEN 1 ELSE 0 END) as closing', [Kunjungan::HASIL_CLOSING])
            ->groupBy('kunjungan_produk.produk')
            ->get()
            ->keyBy('produk');

        $out = [];
        foreach (self::PRODUK_GRUP as $judul => $produkList) {
            $labels = [];
            $ditawarkan = [];
            $closing = [];

            foreach ($produkList as $produk) {
                $row = $rows[$produk] ?? null;
                $labels[] = self::PRODUK_LABEL[$produk] ?? $produk;
                $ditawarkan[] = (int) ($row->ditawarkan ?? 0);
                $closing[] = (int) ($row->closing ?? 0);
            }

            $totalDitawarkan = array_sum($ditawarkan);
            $totalClosing = array_sum($closing);

            $out[] = [
                'judul' => $judul,
                'labels' => $labels,
                'ditawarkan' => $ditawarkan,
                'closing' => $closing,
                'total_ditawarkan' => $totalDitawarkan,
                'total_closing' => $totalClosing,
                'closing_rate' => $totalDitawarkan > 0 ? round($totalClosing / $totalDitawarkan * 100, 1) : 0,
            ];
        }

        return $out;
    }
}
