<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Summary Kunjungan as .xlsx. Built from the rows LaporanController already
 * assembled for the screen (see summaryKunjunganData()), not from a fresh
 * query, so the file and the page can never disagree.
 *
 * The table is a hierarchy, not a flat list: it interleaves Cabang rows with
 * Area and Cabang-Cluster subtotals and a grand TOTAL. On screen the
 * indentation makes that obvious; in a spreadsheet it would not be, and
 * anyone selecting a column and reading the sum would get roughly four times
 * the real figure. Hence the leading Level column — it's what stops the file
 * from being quietly misread.
 */
class SummaryKunjunganExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    private const LEVEL_LABEL = [
        'area' => 'Area',
        'cluster' => 'Cabang-Cluster',
        'cabang' => 'Cabang',
        'total' => 'TOTAL',
    ];

    /**
     * @param  array<int, array{level: string, label: string, values: array<string, mixed>}>  $rows
     * @param  array<int, string>  $stages
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $stages,
        private readonly string $dari,
        private readonly string $sampai,
    ) {}

    public function title(): string
    {
        return 'Summary Kunjungan';
    }

    public function headings(): array
    {
        return array_merge(
            ['Level', 'Report Sales', 'Jumlah Sales', 'Jumlah POI'],
            $this->stages,
            ['Total Kunjungan', '%-Tase Closing'],
        );
    }

    public function array(): array
    {
        $out = [];

        foreach ($this->rows as $row) {
            $nilai = $row['values'];

            $baris = [
                self::LEVEL_LABEL[$row['level']] ?? $row['level'],
                $row['label'],
                (int) ($nilai['jumlah_sales'] ?? 0),
                (int) ($nilai['jumlah_poi'] ?? 0),
            ];

            foreach ($this->stages as $stage) {
                $baris[] = (int) ($nilai[$stage] ?? 0);
            }

            $baris[] = (int) ($nilai['total_kunjungan'] ?? 0);
            // Written as a real number so it can be charted or sorted; the
            // percent sign lives in the column heading, not in the cell.
            $baris[] = $nilai['persen_closing'];

            $out[] = $baris;
        }

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $kolomTerakhir = $sheet->getHighestColumn();

        // The date window the figures were taken over — without it a saved
        // file is just numbers with no idea what period they cover.
        $sheet->insertNewRowBefore(1, 1);
        $sheet->setCellValue('A1', "Periode {$this->dari} s/d {$this->sampai}");
        $sheet->mergeCells("A1:{$kolomTerakhir}1");

        $styles = [
            1 => ['font' => ['italic' => true, 'size' => 10]],
            2 => ['font' => ['bold' => true]],
        ];

        // Subtotal and grand-total rows in bold, so the hierarchy still reads
        // as a hierarchy once the indentation is gone.
        foreach ($this->rows as $i => $row) {
            if ($row['level'] !== 'cabang') {
                $styles[$i + 3] = ['font' => ['bold' => true]];
            }
        }

        return $styles;
    }
}
