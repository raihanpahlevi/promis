<?php

namespace App\Exports;

use App\Models\Kunjungan;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Real .xlsx export (PhpSpreadsheet via maatwebsite/excel), not the old
 * system's HTML-pretending-to-be-.xls trick (PRD §3/§6).
 *
 * FromQuery (not a pre-loaded Collection) so Laravel Excel reads the result
 * set via a cursor/chunked query internally rather than materializing every
 * row in PHP memory at once — same "LIMIT/OFFSET bertahap" performance
 * requirement as the POI import, just for the write direction.
 */
class KunjunganExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  array<int>  $kantorIds  Already-resolved & authorized kantor scope
     *                                 (see ExportController) — this class trusts
     *                                 its caller, it doesn't re-derive RBAC itself.
     */
    public function __construct(
        private readonly array $kantorIds,
        private readonly ?string $hasil = null,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
        private readonly ?int $salesId = null,
        private readonly ?string $poi = null,
    ) {}

    public function query(): Builder
    {
        return Kunjungan::query()
            ->with(['poi.kantor', 'sales', 'produkList'])
            ->whereHas('poi', function ($q) {
                $q->whereIn('kantor_id', $this->kantorIds);
                if ($this->poi) {
                    $q->where('nama_poi', 'like', '%'.$this->poi.'%');
                }
            })
            ->when($this->hasil, fn ($q, $hasil) => $q->where('hasil', $hasil))
            ->when($this->dari, fn ($q, $dari) => $q->whereDate('tanggal_kunjungan', '>=', $dari))
            ->when($this->sampai, fn ($q, $sampai) => $q->whereDate('tanggal_kunjungan', '<=', $sampai))
            ->when($this->salesId, fn ($q, $salesId) => $q->where('sales_id', $salesId))
            ->orderBy('tanggal_kunjungan')
            ->orderBy('id');
    }

    public function headings(): array
    {
        return [
            'Tanggal', 'Kantor', 'Nama POI', 'Alamat', 'Sektor', 'Area', 'Status Mitra', 'PIC',
            'Sales', 'Produk Ditawarkan', 'Hasil', 'Nominal', 'Catatan',
        ];
    }

    /**
     * @param  Kunjungan  $row
     */
    public function map($row): array
    {
        return [
            optional($row->tanggal_kunjungan)->format('Y-m-d'),
            $this->safe($row->poi->kantor->nama ?? ''),
            $this->safe($row->poi->nama_poi ?? ''),
            $this->safe($row->poi->alamat ?? ''),
            $this->safe($row->poi->sektor ?? ''),
            $this->safe($row->poi->area ?? ''),
            $this->safe($row->poi->status_mitra ?? ''),
            $this->safe($row->poi->pic ?? ''),
            $this->safe($row->sales->nama_lengkap ?? ''),
            $this->safe($row->produkList->pluck('produk')->implode(', ')),
            $this->safe($row->hasil),
            $row->nominal !== null ? (float) $row->nominal : null,
            $this->safe($row->catatan ?? ''),
        ];
    }

    /**
     * Anti formula-injection (PRD §7): a cell value starting with =, +, -, or @
     * is interpreted by Excel/Sheets as a formula when the file is opened —
     * a POI name, PIC, or catatan field is free text an end user typed, so it
     * must never be trusted verbatim into a spreadsheet cell. Prefixing with a
     * single quote forces it to render as literal text.
     */
    private function safe(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
