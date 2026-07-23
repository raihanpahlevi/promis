<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Single-select Area -> Cabang-Cluster narrowing (2026-07-23), shared by
 * every screen that has a single-select Cabang filter (POI list, Kunjungan
 * riwayat, Histogram Dashboard, Kelola User) — extracted so the narrowing
 * logic isn't hand-copied 4 times with a chance of subtly diverging. The
 * Dashboard and Rekap Sales screens do NOT use this trait: their Cabang
 * filter is multi-select (chip picker + Terapkan), which needs the array
 * shape DashboardController::buildHierarchicalScope() already implements —
 * this trait's single `?area=`/`?cluster=` auto-submit shape matches the
 * plain `<select onchange="submit()">` pattern those 4 screens already use
 * for their existing filters (Status/Sektor/Role/etc.), so Area/Cabang-
 * Cluster behave identically to filters already on the same page.
 *
 * Narrows one level at a time, each computed from the CURRENT request's
 * area/cluster — not live client-side cascading. Selecting Area resets
 * Cluster (a stale `?cluster=` from a different Area's options is dropped
 * since it won't be in that Area's $clusterOptions), same defensive pattern
 * as DashboardController's own hierarchy.
 */
trait NarrowsKantorByAreaCluster
{
    /**
     * $allowedKantor must already be role-scoped by the caller (admin: every
     * kantor; admin_final: user_kantor only) and must carry `area`/
     * `cabang_cluster` (i.e. fetched without a restrictive select() list).
     *
     * @return array{kantorOptions: Collection, areaOptions: Collection, selectedArea: ?string, clusterOptions: Collection, selectedCluster: ?string}
     */
    private function narrowKantorByAreaCluster(Request $request, Collection $allowedKantor): array
    {
        $areaOptions = $allowedKantor->pluck('area')->filter()->unique()->sort()->values();
        $selectedArea = $request->filled('area') && $areaOptions->contains($request->input('area'))
            ? $request->input('area')
            : null;

        $kantorInArea = $selectedArea !== null
            ? $allowedKantor->where('area', $selectedArea)->values()
            : $allowedKantor;

        $clusterOptions = $kantorInArea->pluck('cabang_cluster')->filter()->unique()->sort()->values();
        $selectedCluster = $request->filled('cluster') && $clusterOptions->contains($request->input('cluster'))
            ? $request->input('cluster')
            : null;

        $kantorInCluster = $selectedCluster !== null
            ? $kantorInArea->where('cabang_cluster', $selectedCluster)->values()
            : $kantorInArea;

        return [
            'kantorOptions' => $kantorInCluster,
            'areaOptions' => $areaOptions,
            'selectedArea' => $selectedArea,
            'clusterOptions' => $clusterOptions,
            'selectedCluster' => $selectedCluster,
        ];
    }
}
