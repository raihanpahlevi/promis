<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kode', 'nama', 'is_active'])]
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
        ];
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
