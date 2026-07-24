<?php

namespace App\Jobs;

use App\Imports\PoiImport;
use App\Imports\UserImport;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Runs one queued Excel import (POI or User) in the background. Exists
 * because synchronous web imports on this shared-hosting setup routinely
 * outlive the ~60s gateway timeout (a 6k-row POI file is ±5-6 queries per
 * row; a user file bcrypt-hashes every password) — the browser got a 504
 * while PHP kept importing, which read as "gagal" and invited dangerous
 * re-uploads. Now the upload returns instantly and this job does the work
 * on the queue:work cron, where no gateway is watching.
 *
 * Only the ImportJob id is serialized into the queue payload; everything
 * else (file path, sheet name, acting user) lives on the import_jobs row,
 * which doubles as the status record the import pages render.
 */
class ProcessImport implements ShouldQueue
{
    use Queueable;

    /** Detail rows kept in result_summary — the aggregate reasons map covers the rest. */
    private const MAX_DETAIL_ROWS = 30;

    public int $timeout = 3300;

    public int $tries = 1;

    public function __construct(public int $importJobId) {}

    public function handle(): void
    {
        $job = ImportJob::find($this->importJobId);

        // Vanished or already picked up (double-fired cron, manual retry) — nothing to do.
        if (! $job || $job->status !== ImportJob::STATUS_PENDING) {
            return;
        }

        $job->update(['status' => ImportJob::STATUS_PROCESSING, 'started_at' => now()]);

        set_time_limit(0);
        ini_set('memory_limit', '-1');

        try {
            $import = $this->buildImporter($job);

            Excel::import($import, Storage::disk('local')->path($job->stored_path));

            $failures = $import->failures();
            $reasons = [];
            $details = [];

            foreach ($failures as $failure) {
                foreach ($failure->errors() as $message) {
                    $reasons[$message] = ($reasons[$message] ?? 0) + 1;
                }
                if (count($details) < self::MAX_DETAIL_ROWS) {
                    $details[] = [
                        'row' => $failure->row(),
                        'attribute' => $failure->attribute(),
                        'errors' => $failure->errors(),
                    ];
                }
            }
            arsort($reasons);

            $job->update([
                'status' => ImportJob::STATUS_DONE,
                'imported_count' => $import->importedCount(),
                'rejected_count' => $failures->count(),
                'finished_at' => now(),
                'result_summary' => [
                    'reasons' => $reasons,
                    'details' => $details,
                    'technical_errors' => array_map(fn ($e) => $e->getMessage(), $import->errors()),
                ],
            ]);
        } catch (Throwable $e) {
            // Both importers implement SkipsOnError, so per-row failures never
            // land here — only bootstrap-level ones do (unreadable file, no
            // sheet with the expected name, DB down). Same taxonomy as the old
            // synchronous controllers.
            Log::error('ProcessImport: import gagal total.', [
                'import_job_id' => $job->id,
                'exception' => $e->getMessage(),
            ]);

            $job->update([
                'status' => ImportJob::STATUS_FAILED,
                'finished_at' => now(),
                'result_summary' => ['error' => $e->getMessage()],
            ]);
        } finally {
            Storage::disk('local')->delete($job->stored_path);
        }
    }

    private function buildImporter(ImportJob $job): PoiImport|UserImport
    {
        if ($job->type === ImportJob::TYPE_USER) {
            return new UserImport($job->sheet_name);
        }

        // PoiImport scopes writable kantor to the acting user (admin_final may
        // only import into their own kantor), so the uploader must still exist.
        $user = User::find($job->created_by);

        if (! $user) {
            throw new \RuntimeException('User pengunggah sudah tidak ada — import dibatalkan.');
        }

        return new PoiImport($user, $job->sheet_name);
    }

    /**
     * Queue-level failure (timeout kill, lost DB connection at the wrong
     * moment): make sure the row never sticks at "processing" forever.
     */
    public function failed(?Throwable $e): void
    {
        ImportJob::where('id', $this->importJobId)
            ->whereIn('status', [ImportJob::STATUS_PENDING, ImportJob::STATUS_PROCESSING])
            ->update([
                'status' => ImportJob::STATUS_FAILED,
                'finished_at' => now(),
                'result_summary' => ['error' => $e?->getMessage() ?? 'Job dihentikan (timeout / worker mati).'],
            ]);
    }
}
