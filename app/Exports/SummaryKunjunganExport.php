<?php

namespace App\Exports;

/**
 * Summary Kunjungan as .xlsx. Built from the rows LaporanController already
 * assembled for the screen (summaryKunjunganData()), not from a fresh query,
 * so the file and the page can never disagree.
 *
 * See HierarchySummaryExport for the Level column and the period caption.
 */
class SummaryKunjunganExport extends HierarchySummaryExport
{
    /**
     * @param  array<int, array{level: string, label: string, values: array<string, mixed>}>  $rows
     * @param  array<int, string>  $stages
     */
    public function __construct(
        array $rows,
        private readonly array $stages,
        string $dari,
        string $sampai,
    ) {
        parent::__construct($rows, $dari, $sampai);
    }

    public function title(): string
    {
        return 'Summary Kunjungan';
    }

    protected function labelHeading(): string
    {
        return 'Report Sales';
    }

    protected function valueHeadings(): array
    {
        return array_merge(
            ['Jumlah Sales', 'Jumlah POI'],
            $this->stages,
            ['Total Kunjungan', '%-Tase Closing'],
        );
    }

    protected function valueCells(array $values): array
    {
        $cells = [
            (int) ($values['jumlah_sales'] ?? 0),
            (int) ($values['jumlah_poi'] ?? 0),
        ];

        foreach ($this->stages as $stage) {
            $cells[] = (int) ($values[$stage] ?? 0);
        }

        $cells[] = (int) ($values['total_kunjungan'] ?? 0);
        // Written as a real number so it can be charted or sorted; the percent
        // sign lives in the column heading, not in the cell.
        $cells[] = $values['persen_closing'];

        return $cells;
    }
}
