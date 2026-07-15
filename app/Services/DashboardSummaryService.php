<?php

namespace App\Services;

use App\Models\DashboardSummary;
use App\Models\Kantor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Keeps `dashboard_summary` up to date via small, atomic deltas applied at
 * write time (PoiObserver / KunjunganObserver) — the dashboard is meant to
 * read this table, never COUNT()/SUM() the 184k-row `poi` table directly
 * (PRD §5).
 *
 * Grain: one row per (tanggal, kantor_id), plus a row per day for the
 * sentinel "ALL" kantor (see Kantor::SENTINEL_ALL_KODE) that aggregates
 * every kantor — this stands in for a NULL-kantor "global" row, which MySQL
 * can't enforce as unique(tanggal, kantor_id) since it allows duplicate NULLs.
 *
 * POI counts are cumulative state, so a new day's row is seeded by carrying
 * forward the previous row's POI totals; kunjungan/closing counts reset to 0
 * each day since they're daily activity, not cumulative.
 *
 * Every mutation goes through applyDeltas() (2026-07-15 fix), which (a) holds
 * the row's lockForUpdate() for the fetch-or-create AND the delta application
 * in one transaction — previously the lock was released as soon as ensureRow()
 * returned, before increment()/decrement() ran, so two concurrent callers
 * could both read the same starting value and lose one side of the update —
 * and (b) floors every counter at 0. All six columns are `unsignedInteger`;
 * without the floor, any drift (a still-possible race elsewhere, a manual
 * DB fix gone slightly wrong, etc.) pushing a counter below zero throws a
 * hard "Out of range value" SQL error under MySQL strict mode and crashes
 * whatever request triggered it, instead of the cache just being off by one.
 */
class DashboardSummaryService
{
    private ?int $allKantorIdCache = null;

    public function adjustPoi(int $kantorId, string $statusMitra, int $delta, ?CarbonInterface $date = null): void
    {
        $date ??= Carbon::today();
        $statusColumn = $this->statusColumn($statusMitra);

        foreach (array_unique([$kantorId, $this->allKantorId()]) as $id) {
            $this->applyDeltas($id, $date, ['total_poi' => $delta, $statusColumn => $delta]);
        }
    }

    /**
     * POI reassigned to a different kantor: only the two kantor-specific rows
     * change (total_poi in the global "ALL" row is unaffected — the POI didn't
     * appear or disappear, it just moved).
     */
    public function movePoiKantor(int $fromKantorId, int $toKantorId, string $statusMitra, ?CarbonInterface $date = null): void
    {
        $date ??= Carbon::today();
        $statusColumn = $this->statusColumn($statusMitra);

        $this->applyDeltas($fromKantorId, $date, ['total_poi' => -1, $statusColumn => -1]);
        $this->applyDeltas($toKantorId, $date, ['total_poi' => 1, $statusColumn => 1]);
    }

    public function recordKunjungan(int $kantorId, bool $isClosing, ?CarbonInterface $date = null): void
    {
        $date ??= Carbon::today();

        foreach (array_unique([$kantorId, $this->allKantorId()]) as $id) {
            $this->applyDeltas($id, $date, array_filter([
                'total_kunjungan' => 1,
                'total_closing' => $isClosing ? 1 : null,
            ], fn ($v) => $v !== null));
        }
    }

    /**
     * Undoes recordKunjungan() — used when an admin/admin_final reopens a
     * mistaken Closing/Collecting Dokumen entry (KunjunganController::reopen()).
     * KunjunganObserver only has created(), not deleted(), so removing a
     * kunjungan row never adjusts these counters on its own; the reopen flow
     * has to call this explicitly.
     */
    public function reverseKunjungan(int $kantorId, bool $isClosing, ?CarbonInterface $date = null): void
    {
        $date ??= Carbon::today();

        foreach (array_unique([$kantorId, $this->allKantorId()]) as $id) {
            $this->applyDeltas($id, $date, array_filter([
                'total_kunjungan' => -1,
                'total_closing' => $isClosing ? -1 : null,
            ], fn ($v) => $v !== null));
        }
    }

    /**
     * Fetch-or-create the row AND apply every delta inside one transaction
     * with the row locked the whole time, floored at 0 per column — see
     * class docblock for why both of those matter.
     *
     * @param  array<string, int>  $deltas  column => delta
     */
    private function applyDeltas(int $kantorId, CarbonInterface $date, array $deltas): void
    {
        DB::transaction(function () use ($kantorId, $date, $deltas) {
            $row = $this->ensureRow($kantorId, $date);

            $updates = [];
            foreach ($deltas as $column => $delta) {
                $updates[$column] = max(0, $row->{$column} + $delta);
            }

            $row->update($updates);
        });
    }

    private function ensureRow(int $kantorId, CarbonInterface $date): DashboardSummary
    {
        $row = DashboardSummary::where('kantor_id', $kantorId)
            ->where('tanggal', $date->toDateString())
            ->lockForUpdate()
            ->first();

        if ($row) {
            return $row;
        }

        $previous = DashboardSummary::where('kantor_id', $kantorId)
            ->where('tanggal', '<', $date->toDateString())
            ->orderByDesc('tanggal')
            ->first();

        return DashboardSummary::create([
            'tanggal' => $date->toDateString(),
            'kantor_id' => $kantorId,
            'total_poi' => $previous->total_poi ?? 0,
            'poi_bukan_nasabah' => $previous->poi_bukan_nasabah ?? 0,
            'poi_non_merchant' => $previous->poi_non_merchant ?? 0,
            'poi_merchant' => $previous->poi_merchant ?? 0,
            'total_kunjungan' => 0,
            'total_closing' => 0,
        ]);
    }

    private function statusColumn(string $statusMitra): string
    {
        return match ($statusMitra) {
            'Bukan Nasabah BNI' => 'poi_bukan_nasabah',
            'Nasabah Non Merchant BNI' => 'poi_non_merchant',
            'Nasabah Merchant BNI' => 'poi_merchant',
            default => throw new RuntimeException("status_mitra tidak dikenal: {$statusMitra}"),
        };
    }

    private function allKantorId(): int
    {
        return $this->allKantorIdCache ??= Kantor::where('kode', Kantor::SENTINEL_ALL_KODE)->value('id')
            ?? throw new RuntimeException('Sentinel kantor "'.Kantor::SENTINEL_ALL_KODE.'" belum di-seed. Jalankan DatabaseSeeder.');
    }
}
