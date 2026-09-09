<?php

namespace App\Exports;

/**
 * Summary Produk as .xlsx — products counted from Closing visits only, same
 * basis as the screen (summaryProdukData()).
 *
 * See HierarchySummaryExport for the Level column and the period caption.
 */
class SummaryProdukExport extends HierarchySummaryExport
{
    /**
     * @param  array<int, array{level: string, label: string, values: array<string, mixed>}>  $rows
     * @param  array<int, string>  $produkList
     */
    public function __construct(
        array $rows,
        private readonly array $produkList,
        string $dari,
        string $sampai,
    ) {
        parent::__construct($rows, $dari, $sampai);
    }

    public function title(): string
    {
        return 'Summary Produk';
    }

    protected function labelHeading(): string
    {
        return 'Report Product';
    }

    protected function valueHeadings(): array
    {
        return array_merge($this->produkList, ['Total']);
    }

    protected function valueCells(array $values): array
    {
        $cells = [];

        foreach ($this->produkList as $produk) {
            $cells[] = (int) ($values[$produk] ?? 0);
        }

        $cells[] = (int) ($values['total_produk'] ?? 0);

        return $cells;
    }
}
