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

    /**
     * Marks jobs stuck in pending/processing for hours as failed. A job can
     * get stranded when the PHP process running it is hard-killed (FPM
     * restart, host maintenance) — failed() never fires then, and without
     * this sweep the import page would show "Sedang diproses" forever (and
     * keep auto-refreshing). Called from the import pages' create() so the
     * sweep runs exactly where the stale status would be seen.
     */
    public static function sweepStale(): void
    {
        static::whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
            ->where('created_at', '<', now()->subHours(3))
            ->update([
                'status' => self::STATUS_FAILED,
                'finished_at' => now(),
                'result_summary' => ['error' => 'Proses terputus di tengah jalan (server restart) — silakan upload ulang.'],
            ]);
    }
}
