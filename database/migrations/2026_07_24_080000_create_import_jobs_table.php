<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking table for background (queued) Excel imports — one row per uploaded
 * file. This is the status the import pages poll, NOT Laravel's own `jobs`
 * table (that one is queue plumbing: rows vanish the moment a worker picks
 * them up, which is exactly when the user most wants to see "sedang
 * diproses").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // 'poi' | 'user'
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('sheet_name')->nullable();
            $table->string('status')->default('pending'); // pending|processing|done|failed
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            // Aggregated failure reasons + capped row details, JSON. Text (not
            // json column) keeps it portable across the sqlite test DB and
            // prod MySQL.
            $table->text('result_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
