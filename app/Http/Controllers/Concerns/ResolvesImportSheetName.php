<?php

namespace App\Http\Controllers\Concerns;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Shared by PoiImportController/UserImportController: a single-sheet file
 * (any tab name) is used as-is — the exact literal sheet name (e.g. "Data
 * POI"/"Data User") is only needed to disambiguate multi-sheet files, like
 * the official templates which also ship a "Petunjuk" instructions sheet.
 */
trait ResolvesImportSheetName
{
    /**
     * Returns null for CSV (no sheet concept — Laravel Excel bypasses sheet
     * name matching for those entirely) or multi-sheet files, both of which
     * should fall back to the caller's own literal default sheet name.
     */
    private function resolveSheetName(string $path): ?string
    {
        $reader = IOFactory::createReaderForFile($path);

        if (! method_exists($reader, 'listWorksheetNames')) {
            return null;
        }

        $reader->setReadDataOnly(true);
        $names = $reader->listWorksheetNames($path);

        return count($names) === 1 ? $names[0] : null;
    }
}
