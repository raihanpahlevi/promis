<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['poi_id', 'alasan_gagal', 'attempt_count', 'last_attempt_at'])]
class GeocodeFailed extends Model
{
    protected $table = 'geocode_failed';

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
        ];
    }

    public function poi(): BelongsTo
    {
        return $this->belongsTo(Poi::class);
    }
}
