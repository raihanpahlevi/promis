<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One background Excel import (POI or User) — created at upload time by the
 * import controllers, picked up by App\Jobs\ProcessImport via the queue, and
 * rendered as "Riwayat Import" on the import pages. See the migration for
 * why this exists separately from Laravel's `jobs` table.
 */
#[Fillable([
    'type', 'original_filename', 'stored_path', 'sheet_name', 'status',
    'imported_count', 'rejected_count', 'result_summary', 'created_by',
    'started_at', 'finished_at',
])]
class ImportJob extends Model
{
    public const TYPE_POI = 'poi';

    public const TYPE_USER = 'user';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'result_summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }
}
