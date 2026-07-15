<?php

namespace App\Models;

use App\Observers\KunjunganObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['poi_id', 'sales_id', 'tanggal_kunjungan', 'hasil', 'nominal', 'catatan'])]
#[ObservedBy(KunjunganObserver::class)]
class Kunjungan extends Model
{
    // 6-stage sales funnel, confirmed from the v1 dashboard source (kunjungan_sales.hasil).
    public const HASIL_BELUM_BERTEMU = 'Belum Bertemu Key Person';

    public const HASIL_BELUM_BERMINAT = 'Belum Berminat';

    public const HASIL_BERMINAT = 'Berminat';

    public const HASIL_NEGO_PRICING = 'Nego Pricing';

    public const HASIL_COLLECTING_DOKUMEN = 'Collecting Dokumen';

    public const HASIL_CLOSING = 'Closing';

    public const HASIL_OPTIONS = [
        self::HASIL_BELUM_BERTEMU,
        self::HASIL_BELUM_BERMINAT,
        self::HASIL_BERMINAT,
        self::HASIL_NEGO_PRICING,
        self::HASIL_COLLECTING_DOKUMEN,
        self::HASIL_CLOSING,
    ];

    /**
     * Closed list of the 18 canonical BNI products the v1 dashboard reports against.
     * The old system stored these as a comma-joined free-text column and fuzzy
     * keyword-LIKE-matched it back to this same list at report time (a data-quality
     * problem this rebuild is meant to fix). v2 stores one row per selected product
     * in `kunjungan_produk` (a visit can offer several products at once — confirmed
     * multi-select against the real v1 form) instead, so per-product reporting stays
     * an exact GROUP BY.
     */
    public const PRODUK_OPTIONS = [
        'Tabungan',
        'Giro',
        'Deposito',
        'KUR',
        'Kredit SME',
        'BNI Fleksi',
        'BWU',
        'BNI Griya',
        'Kartu Kredit',
        'EDC',
        'QRIS',
        'BNI Direct',
        'Trade Finance',
        'Garansi Bank',
        'AGEN46',
        'Payroll',
        'BIONS Sekuritas',
        'Wondr by BNI',
    ];

    /**
     * Badge color mapping for the 6-stage funnel in riwayat tables — Closing reads as
     * "won" (green), Belum Berminat as a dead end (red), everything still in motion in
     * between as pending (amber).
     */
    public const HASIL_BADGE_CLASS = [
        self::HASIL_BELUM_BERTEMU => 'badge-pending',
        self::HASIL_BELUM_BERMINAT => 'badge-no',
        self::HASIL_BERMINAT => 'badge-pending',
        self::HASIL_NEGO_PRICING => 'badge-pending',
        self::HASIL_COLLECTING_DOKUMEN => 'badge-pending',
        self::HASIL_CLOSING => 'badge-ok',
    ];

    protected $table = 'kunjungan';

    protected function casts(): array
    {
        return [
            // Explicit Y-m-d (not just 'date'): MySQL's DATE column silently truncates any
            // extra time component, masking that Eloquent's default 'date' cast serializes
            // to the connection's full datetime format — but SQLite's DATE column has no
            // such enforcement and stores the literal string, breaking whereBetween() date
            // comparisons in tests. Same root cause already found in DashboardSummary::tanggal.
            'tanggal_kunjungan' => 'date:Y-m-d',
            'nominal' => 'decimal:2',
        ];
    }

    public function poi(): BelongsTo
    {
        return $this->belongsTo(Poi::class);
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }

    public function produkList(): HasMany
    {
        return $this->hasMany(KunjunganProduk::class);
    }

    public function hasilBadgeClass(): string
    {
        return self::HASIL_BADGE_CLASS[$this->hasil] ?? 'badge-pending';
    }
}
