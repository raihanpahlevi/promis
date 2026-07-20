<?php

namespace App\Exports;

use App\Models\Poi;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * POI export — same column set (minus ID) as PoiImport's expected headings,
 * PLUS a leading ID column PoiImport now reads to support round-tripping
 * this exact file back through the importer as an update instead of a new
 * insert (2026-07-16): "narik data, isi kolom yang kosong di lokal, upload
 * lagi" must land on the SAME poi row (so its kunjungan history stays
 * attached), not create a duplicate. ID is a plain surrogate key — safe to
 * expose, PoiImport re-validates it against the acting user's kantor scope
 * on the way back in rather than trusting it blindly.
 */
class PoiExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  array<int>  $kantorIds  Already-resolved & authorized kantor scope
     *                                 (see ExportController) — this class trusts
     *                                 its caller, it doesn't re-derive RBAC itself.
     */
    public function __construct(
        private readonly array $kantorIds,
        private readonly ?string $status = null,
        private readonly ?string $area = null,
        private readonly ?string $sektor = null,
        private readonly ?string $statusMitra = null,
        private readonly ?string $q = null,
    ) {}

    public function query(): Builder
    {
        return Poi::query()
            ->with('kantor')
            ->whereIn('kantor_id', $this->kantorIds)
            ->when($this->status, fn ($q, $status) => $q->where('status', $status))
            ->when($this->area, fn ($q, $area) => $q->where('area', $area))
            ->when($this->sektor, fn ($q, $sektor) => $q->where('sektor', $sektor))
            ->when($this->statusMitra, fn ($q, $statusMitra) => $q->where('status_mitra', $statusMitra))
            ->when($this->q, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nama_poi', 'like', "%{$search}%")
                        ->orWhere('alamat', 'like', "%{$search}%")
                        ->orWhere('pic', 'like', "%{$search}%");
                });
            })
            ->orderBy('id');
    }

    public function headings(): array
    {
        return ['ID', 'Nama', 'Alamat', 'Sektor', 'Sub Sektor', 'Area', 'Outlet', 'Bank', 'PIC'];
    }

    /**
     * @param  Poi  $row
     */
    public function map($row): array
    {
        return [
            $row->id,
            $this->safe($row->nama_poi),
            $this->safe($row->alamat),
            $this->safe($row->sektor),
            $this->safe($row->sub_sektor),
            $this->safe($row->area),
            $this->safe($row->kantor->nama ?? ''),
            $this->safe($row->status_mitra),
            $this->safe($row->pic),
        ];
    }

    /**
     * Anti formula-injection (same rule as KunjunganExport) — nama_poi/alamat/
     * pic are free text a user typed, never trusted verbatim into a cell.
     */
    private function safe(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
