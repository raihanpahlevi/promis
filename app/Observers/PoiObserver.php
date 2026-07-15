<?php

namespace App\Observers;

use App\Models\Poi;
use App\Services\DashboardSummaryService;

class PoiObserver
{
    public function __construct(private DashboardSummaryService $summary) {}

    public function created(Poi $poi): void
    {
        // "nonaktif" isn't a real DB delete (see poi_reopen_log) — a POI created
        // straight into that state (unusual, but not disallowed) shouldn't count.
        if ($poi->status === 'aktif') {
            $this->summary->adjustPoi($poi->kantor_id, $poi->status_mitra, 1);
        }
    }

    /**
     * Handles kantor reassignment, status_mitra correction, and the hapus/reopen
     * soft-delete toggle (status aktif<->nonaktif). Whether "Total POI" on the
     * dashboard should count nonaktif rows is a call for Tahap 5 once the v1
     * reference lands — this assumes "nonaktif == not counted", easy to flip here.
     *
     * Rewritten 2026-07-15 to fix a latent bug (not reachable via any current
     * PoiController path, but a real landmine for future bulk-edit/API code):
     * the old logic checked `wasChanged('status')` FIRST and, on a match,
     * applied the delta using $poi->kantor_id/$poi->status_mitra — the NEW
     * post-save values — even when kantor_id/status_mitra changed in that
     * SAME update() call. Deactivating a POI while also reassigning its
     * kantor in one save would decrement the wrong (new) kantor's count and
     * never touch the kantor it was actually counted under, permanently
     * drifting both. The fix branches on wasActive/isActive explicitly and
     * always adjusts the bucket the POI was actually counted in (original
     * kantor/status_mitra when deactivating, new ones when reactivating)
     * regardless of what else changed in the same call.
     */
    public function updated(Poi $poi): void
    {
        $wasActive = $poi->getOriginal('status') === 'aktif';
        $isActive = $poi->status === 'aktif';

        // Nonaktif before and after: never counted, still isn't — nothing
        // to adjust no matter what else changed in this save.
        if (! $wasActive && ! $isActive) {
            return;
        }

        $originalKantorId = (int) $poi->getOriginal('kantor_id');
        $originalStatusMitra = $poi->getOriginal('status_mitra');

        if ($wasActive && ! $isActive) {
            // Deactivating: remove from wherever it was actually counted
            // BEFORE this save (the original kantor/status_mitra) — not the
            // new ones, even if kantor_id/status_mitra also changed here.
            $this->summary->adjustPoi($originalKantorId, $originalStatusMitra, -1);

            return;
        }

        if (! $wasActive && $isActive) {
            // Reactivating: add to wherever it will be counted going
            // forward (the new kantor/status_mitra) — it was never counted
            // anywhere while nonaktif, so there's no "original" to remove.
            $this->summary->adjustPoi($poi->kantor_id, $poi->status_mitra, 1);

            return;
        }

        // Stayed aktif the whole time — only kantor_id/status_mitra changes
        // (if any) matter now, same as before.
        if ($poi->wasChanged('kantor_id') && $poi->wasChanged('status_mitra')) {
            $this->summary->adjustPoi($originalKantorId, $originalStatusMitra, -1);
            $this->summary->adjustPoi($poi->kantor_id, $poi->status_mitra, 1);

            return;
        }

        if ($poi->wasChanged('kantor_id')) {
            $this->summary->movePoiKantor($originalKantorId, $poi->kantor_id, $poi->status_mitra);
        }

        if ($poi->wasChanged('status_mitra')) {
            $this->summary->adjustPoi($poi->kantor_id, $originalStatusMitra, -1);
            $this->summary->adjustPoi($poi->kantor_id, $poi->status_mitra, 1);
        }
    }

    public function deleted(Poi $poi): void
    {
        if ($poi->status === 'aktif') {
            $this->summary->adjustPoi($poi->kantor_id, $poi->status_mitra, -1);
        }
    }
}
