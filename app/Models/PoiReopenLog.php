<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['poi_id', 'action', 'alasan', 'user_id'])]
class PoiReopenLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'poi_reopen_log';

    public function poi(): BelongsTo
    {
        return $this->belongsTo(Poi::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
