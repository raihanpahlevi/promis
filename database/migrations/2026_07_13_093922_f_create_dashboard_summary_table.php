<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dashboard_summary', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            // NOT NULL by design: MySQL unique index allows duplicate NULLs, which would break the
            // one-row-per-day invariant for the global aggregate. Global rows point to a sentinel
            // "ALL" row in `kantor` (seeded in DatabaseSeeder) instead of using NULL.
            $table->foreignId('kantor_id')->constrained('kantor')->cascadeOnDelete();
            $table->unsignedInteger('total_poi')->default(0);
            $table->unsignedInteger('poi_bukan_nasabah')->default(0);
            $table->unsignedInteger('poi_non_merchant')->default(0);
            $table->unsignedInteger('poi_merchant')->default(0);
            $table->unsignedInteger('total_kunjungan')->default(0);
            $table->unsignedInteger('total_closing')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tanggal', 'kantor_id']);
            $table->index('tanggal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_summary');
    }
};
