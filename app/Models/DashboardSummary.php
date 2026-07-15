<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tanggal', 'kantor_id', 'total_poi', 'poi_bukan_nasabah', 'poi_non_merchant',
    'poi_merchant', 'total_kunjungan', 'total_closing',
])]
class DashboardSummary extends Model
{
    public const CREATED_AT = null;

    protected $table = 'dashboard_summary';

    protected function casts(): array
    {
        return [
            // Explicit Y-m-d format (not just 'date'): without it, Eloquent serializes a
            // plain 'date' cast using the connection's default datetime format
            // (Y-m-d H:i:s), which never matches DashboardSummaryService::ensureRow()'s
            // `where('tanggal', $date->toDateString())` lookup — every second write for
            // the same (kantor_id, tanggal) then misses the existing row and tries to
            // INSERT a duplicate, crashing on the unique(tanggal, kantor_id) constraint.
            // Discovered while building Modul Kunjungan (two dashboard_summary writes on
            // the same day — one from PoiObserver, one from KunjunganObserver — is enough
            // to trigger it), but the bug is in this model's cast, not anything
            // kunjungan-specific, so fixed at the source rather than worked around.
            'tanggal' => 'date:Y-m-d',
        ];
    }

    public function kantor(): BelongsTo
    {
        return $this->belongsTo(Kantor::class);
    }
}
