<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared shape for the two Summary reports' .xlsx exports.
 *
 * Both are the same kind of table: a hierarchy that interleaves Cabang rows
 * with Area and Cabang-Cluster subtotals and a grand TOTAL. On screen the
 * indentation makes that obvious; in a spreadsheet it would not be, and
 * anyone selecting a column and reading the sum would get several times the
 * real figure. So every export here leads with a Level column and prints the
 * subtotal rows in bold, and row 1 names the period the figures cover.
 *
 * Subclasses supply only what differs: the sheet name, the column headings
 * after Level/Report, and the cells for one row.
 */
abstract class HierarchySummaryExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    protected const LEVEL_LABEL = [
        'area' => 'Area',
        'cluster' => 'Cabang-Cluster',
        'cabang' => 'Cabang',
        'total' => 'TOTAL',
    ];

    /**
     * @param  array<int, array{level: string, label: string, values: array<string, mixed>}>  $rows
     */
    public function __construct(
        protected readonly array $rows,
        protected readonly string $dari,
        protected readonly string $sampai,
    ) {}

    /**
     * Heading for the row-label column — "Report Sales" / "Report Product",
     * matching whatever the screen calls it.
     */
    abstract protected function labelHeading(): string;

    /**
     * Column headings after Level and the label column.
     *
     * @return array<int, string>
     */
    abstract protected function valueHeadings(): array;

    /**
     * The value cells for one row, in the same order as valueHeadings().
     *
     * @param  array<string, mixed>  $values
     * @return array<int, mixed>
     */
    abstract protected function valueCells(array $values): array;

    public function headings(): array
    {
        return array_merge(['Level', $this->labelHeading()], $this->valueHeadings());
    }

    public function array(): array
    {
        $out = [];

        foreach ($this->rows as $row) {
            $out[] = array_merge(
                [self::LEVEL_LABEL[$row['level']] ?? $row['level'], $row['label']],
                $this->valueCells($row['values']),
            );
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
