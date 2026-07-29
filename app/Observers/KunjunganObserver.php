<?php

namespace App\Observers;

use App\Models\Kunjungan;
use App\Services\DashboardSummaryService;

class KunjunganObserver
{
    public function __construct(private DashboardSummaryService $summary) {}

    public function created(Kunjungan $kunjungan): void
    {
        $kantorId = $kunjungan->poi?->kantor_id
            ?? \App\Models\Poi::whereKey($kunjungan->poi_id)->value('kantor_id');

        // Keyed by tanggal_kunjungan (not "today") so the trend chart reflects when
        // the visit happened. Note: this means a backdated kunjungan updates an
        // already-closed historical summary row rather than today's — fine for the
        // isolated counter increments here, but out of scope for Tahap 1 to fully
        // reconcile against the carry-forward seeding in ensureRow().
        $this->summary->recordKunjungan(
            $kantorId,
            $kunjungan->hasil === Kunjungan::HASIL_CLOSING,
            $kunjungan->tanggal_kunjungan,
        );
    }

    /**
     * Mirror of created(): any Eloquent delete of a kunjungan takes its
     * dashboard_summary contribution back out. Added 2026-07-29 alongside the
     * "Hapus" action on Riwayat Kunjungan — KunjunganController::reopen() used
     * to call reverseKunjungan() by hand, which meant every NEW delete path
     * had to remember to do the same or silently inflate the dashboard
     * counters. Centralizing it here makes that impossible to forget; reopen()
     * no longer reverses manually (doing both would double-subtract).
     *
     * Only fires for Eloquent deletes. A POI hard-delete wipes its kunjungan
     * via the DB-level cascade, which bypasses observers entirely — see
     * PoiController::destroyPermanent(), which deletes them through Eloquent
     * first precisely so this runs.
     */
    public function deleted(Kunjungan $kunjungan): void
    {
        $kantorId = $kunjungan->poi?->kantor_id
            ?? \App\Models\Poi::whereKey($kunjungan->poi_id)->value('kantor_id');

        if ($kantorId === null) {
            return;
        }

        $this->summary->reverseKunjungan(
            $kantorId,
            $kunjungan->hasil === Kunjungan::HASIL_CLOSING,
            $kunjungan->tanggal_kunjungan,
        );
    }
}
