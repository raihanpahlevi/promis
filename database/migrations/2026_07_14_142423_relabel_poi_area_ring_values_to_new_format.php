<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only migration (no schema change): existing rows still hold the old
 * bare "RING 1".."RING 5" labels from before Poi::AREA_OPTIONS became the
 * descriptive "Ring N (jarak km)" strings (product decision 2026-07-14, only
 * 4 rings now — "RING 5" had no replacement and is cleared instead). Without
 * this backfill, old rows would silently drop out of Dashboard's Top Area
 * breakdown (areaBreakdown() filters on the new RING_LEVELS strings) while
 * still displaying their stale raw label everywhere else — a confusing
 * half-migrated state, not a "kalau area kosong tetap lolos" case.
 */
return new class extends Migration
{
    private const MAP = [
        'RING 1' => 'Ring 1 (0 - 1 Km)',
        'RING 2' => 'Ring 2 (>1 - 3 Km)',
        'RING 3' => 'Ring 3 (>3 - 5 Km)',
        'RING 4' => 'Ring 4 (> 5 Km)',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('poi')->where('area', $old)->update(['area' => $new]);
        }

        // No new-format equivalent for the old 5th ring — left blank rather
        // than guessed, consistent with area being optional everywhere.
        DB::table('poi')->where('area', 'RING 5')->update(['area' => null]);
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('poi')->where('area', $new)->update(['area' => $old]);
        }
    }
};
