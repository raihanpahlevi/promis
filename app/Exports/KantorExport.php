<?php

namespace App\Exports;

use App\Models\Kantor;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Kantor ("Cabang") export — round-trips back through KantorImport as an
 * upsert (bulk-rename/re-code/re-map many kantor at once via Excel instead
 * of one-by-one through the inline form on Kelola Kantor), same pattern as
 * PoiExport/PoiImport. Deliberately built (2026-07-22) because editing a
 * kantor's name by hand-typing a corrected Outlet/Cabang value into a POI
 * import does NOT rename the existing kantor — it creates a brand-new one
 * (that column matches by exact-string against the current name) and leaves
 * the old one orphaned. This file's "ID" column is what lets KantorImport
 * recognize "this is the same kantor, just edited" instead of that footgun —
 * though KantorImport also accepts a plain "Cabang" name match with no ID at
 * all, for uploading an existing Cabang→Cabang-Cluster→Area mapping file
 * as-is (see that class's docblock).
 *
 * Area/Cabang-Cluster (2026-07-23) are the hierarchy a POI's own Area/
 * Cabang-Cluster display is always read through (a POI never stores these
 * itself — see PoiController@show) — this export/KantorImport round-trip is
 * how that mapping gets bulk-set.
 *
 * Excludes the sentinel "ALL" row (Kantor::SENTINEL_ALL_KODE) — that's
 * dashboard_summary's internal global-aggregate bookkeeping, never a real
 * kantor an admin should see or rename here.
 */
class KantorExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return Kantor::query()
            ->where('kode', '!=', Kantor::SENTINEL_ALL_KODE)
            ->orderBy('nama');
    }

    public function headings(): array
    {
        return ['ID', 'Kode', 'Cabang', 'Area', 'Cabang-Cluster', 'Aktif'];
    }

    /**
     * @param  Kantor  $row
     */
    public function map($row): array
    {
        return [
            $row->id,
            $this->safe($row->kode),
            $this->safe($row->nama),
            $this->safe($row->area),
            $this->safe($row->cabang_cluster),
            $row->is_active ? 'Ya' : 'Tidak',
        ];
    }

    /**
     * Anti formula-injection (same rule as PoiExport/KunjunganExport) — kode/
     * nama can contain whatever an admin typed, never trusted verbatim into a cell.
     */
    private function safe(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
