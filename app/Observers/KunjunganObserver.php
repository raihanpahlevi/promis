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
}
