<?php

namespace Tests\Concerns;

use App\Models\ImportJob;

/**
 * Import results moved from the old synchronous `import_summary` session
 * flash to the ImportJob row (queued imports, 2026-07-24). In the test
 * environment the queue runs sync (phpunit.xml QUEUE_CONNECTION=sync), so by
 * the time the POST returns the job has already executed — this helper
 * re-exposes its outcome in the exact array shape the pre-queue assertions
 * were written against, so those assertions didn't have to be rewritten.
 */
trait ReadsImportSummary
{
    /**
     * @return array{imported: int, rejected: int, errors: array, technical_errors: array}
     */
    private function latestImportSummary(): array
    {
        $job = ImportJob::latest('id')->firstOrFail();

        return [
            'imported' => $job->imported_count,
            'rejected' => $job->rejected_count,
            'errors' => $job->result_summary['details'] ?? [],
            'technical_errors' => $job->result_summary['technical_errors'] ?? [],
        ];
    }
}
