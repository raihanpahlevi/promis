<?php

namespace App\Models;

use App\Observers\PoiObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'nama_poi', 'alamat', 'sektor', 'sub_sektor', 'area', 'kantor_id',
    'status_mitra', 'pic', 'latitude', 'longitude', 'geocode_status',
    'status', 'created_by', 'collecting_by',
])]
#[ObservedBy(PoiObserver::class)]
class Poi extends Model
{
    public const SEKTOR_OPTIONS = [
        'Food & Beverage',
        'Retail & Shopping',
        'Education',
        'Business & Office',
        'Health Care',
        'Leisure & Recreation',
        'Travel & Accomodation',
        'Lainnya',
        'Warehouse & Logistic',
        'Wholesaler',
        'Financial Service',
        'Residential Areas',
        'Government & Public Service',
        'Religious Facilities',
        'Manufacture',
        'Shipyard',
    ];

    protected $table = 'poi';

    public const AREA_OPTIONS = [
        'Ring 1 (0 - 1 Km)',
        'Ring 2 (>1 - 3 Km)',
        'Ring 3 (>3 - 5 Km)',
        'Ring 4 (> 5 Km)',
    ];

    public const STATUS_MITRA_OPTIONS = [
        'Bukan Nasabah BNI',
        'Nasabah Non Merchant BNI',
        'Nasabah Merchant BNI',
    ];

    /**
     * A kunjungan can only target a POI that hasn't partnered with BNI yet —
     * confirmed against the real v1 kunjungan.php (its picker query excludes
     * status_mitra='BNI' outright). This is that same "not yet BNI" bucket in
     * the v2 3-value scheme.
     */
    public const BELUM_BERMITRA_BNI = 'Bukan Nasabah BNI';

    /**
     * Valid targets for the "Status Mitra Baru" field a sales fills in when a
     * kunjungan's hasil is Closing — anything except BELUM_BERMITRA_BNI,
     * since closing a visit can't result in "still not a BNI partner".
     */
    public const STATUS_MITRA_AFTER_CLOSING = [
        'Nasabah Non Merchant BNI',
        'Nasabah Merchant BNI',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function kantor(): BelongsTo
    {
        return $this->belongsTo(Kantor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function collectingBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collecting_by');
    }

    public function kunjungan(): HasMany
    {
        return $this->hasMany(Kunjungan::class);
    }

    public function reopenLogs(): HasMany
    {
        return $this->hasMany(PoiReopenLog::class);
    }

    /**
     * Green once it's a BNI partner (Merchant or Non Merchant), red while
     * it's still BELUM_BERMITRA_BNI — labels stay the full status_mitra
     * text either way, only the color signals partner vs not yet.
     */
    public function statusMitraBadgeClass(): string
    {
        return $this->status_mitra === self::BELUM_BERMITRA_BNI ? 'badge-no' : 'badge-ok';
    }
}
