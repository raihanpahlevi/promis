<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kode', 'nama', 'area', 'cabang_cluster', 'kota_besar', 'is_active'])]
class Kantor extends Model
{
    /**
     * Sentinel kantor code used for the global row of dashboard_summary (kantor_id can't be
     * NULL there because MySQL unique indexes allow duplicate NULLs). Seeded in DatabaseSeeder,
     * hidden from normal kantor pickers via is_active=false.
     */
    public const SENTINEL_ALL_KODE = 'ALL';

    protected $table = 'kantor';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'kota_besar' => 'boolean',
        ];
    }

    /**
     * What each Ring Area label actually means in kilometres. The bands differ
     * by city size, which is the whole reason kantor.kota_besar exists: a
     * "Ring 1" POI is within 1 km of a Padang/Pekanbaru/Batam branch but can
     * be up to 5 km out anywhere else. Keyed to Poi::AREA_OPTIONS.
     */
    public const RING_JARAK_KOTA_BESAR = [
        'Ring 1' => '0-1 km',
        'Ring 2' => '>1-5 km',
        'Ring 3' => '>5-10 km',
    ];

    public const RING_JARAK_KOTA_KECIL = [
        'Ring 1' => '0-5 km',
        'Ring 2' => '>5-10 km',
        'Ring 3' => '>10 km',
    ];

    /**
     * Distance band for a ring at THIS kantor, or null when the ring is blank
     * or carries a value outside Poi::AREA_OPTIONS (import keeps ring free
     * text, so a stale file can still put "Ring 4" in there — better to show
     * the raw label with no distance than to invent one).
     */
    public function ringJarak(?string $ring): ?string
    {
        $peta = $this->kota_besar ? self::RING_JARAK_KOTA_BESAR : self::RING_JARAK_KOTA_KECIL;

        return $peta[trim((string) $ring)] ?? null;
    }

    /**
     * "Ring 1 (0-1 km)" — the label as it should read on screen.
     */
    public function ringLabel(?string $ring): string
    {
        $ring = trim((string) $ring);

        if ($ring === '') {
            return '-';
        }

        $jarak = $this->ringJarak($ring);

        return $jarak === null ? $ring : "{$ring} ({$jarak})";
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_kantor');
    }

    public function poi(): HasMany
    {
        return $this->hasMany(Poi::class);
    }
}
