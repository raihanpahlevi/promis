<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Rekap Sales as .xlsx — one class covering both tabs, because they list the
 * same population from opposite sides: who visited in the range, and who
 * didn't. Which one you get follows the mode the page was on.
 *
 * Flat, one row per person, so unlike the Summary exports there is no
 * hierarchy to label and a column sum here is a real total.
 *
 * Cabang is joined into one cell rather than repeating the person per Cabang:
 * a sales can hold several, and a row per pairing would make any count taken
 * off this sheet larger than the number of people on it — the same mistake
 * that made the Belum Kunjungan card read 3,771 against 695 people.
 */
class RekapSalesExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public const MODE_KUNJUNGAN = 'kunjungan';

    public const MODE_TIDAK = 'tidak';

    /**
     * @param  Collection<int, User>  $rows
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly string $mode,
        private readonly string $dari,
        private readonly string $sampai,
        private readonly ?string $unitLabel = null,
    ) {}

    public function title(): string
    {
        return $this->mode === self::MODE_TIDAK ? 'Belum Kunjungan' : 'Rekap Kunjungan';
    }

    public function headings(): array
    {
        $dasar = ['Nama Sales', 'Unit', 'Cabang'];

        return $this->mode === self::MODE_TIDAK
            ? array_merge($dasar, ['Status'])
            : array_merge($dasar, ['Total Visit', 'Total Closing']);
    }

    public function array(): array
    {
        $out = [];

        foreach ($this->rows as $u) {
            $baris = [
                $u->nama_lengkap,
                $u->unit->nama ?? '-',
                $u->kantor->pluck('nama')->join(', ') ?: '-',
            ];

            if ($this->mode === self::MODE_TIDAK) {
                $baris[] = 'Tidak Kunjungan';
            } else {
                $baris[] = (int) $u->total_visit;
                $baris[] = (int) $u->total_closing;
            }

            $out[] = $baris;
        }

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $kolomTerakhir = $sheet->getHighestColumn();

        // Period and unit filter, so a saved file says what it is a rekap of.
        $sheet->insertNewRowBefore(1, 1);
        $sheet->setCellValue('A1', trim(
            "Periode {$this->dari} s/d {$this->sampai}".
            ($this->unitLabel !== null ? " — Unit {$this->unitLabel}" : ' — Semua Unit')
        ));
        $sheet->mergeCells("A1:{$kolomTerakhir}1");

        return [
            1 => ['font' => ['italic' => true, 'size' => 10]],
            2 => ['font' => ['bold' => true]],
        ];
    }
}
