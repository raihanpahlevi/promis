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
        Schema::table('poi', function (Blueprint $table) {
            // Locks a POI to the sales user currently gathering documents for it
            // (hasil = 'Collecting Dokumen'), so a second sales can't log a
            // competing visit against the same POI mid-process. Cleared back to
            // NULL on any other hasil. Confirmed against the real v1 system.
            $table->foreignId('collecting_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poi', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collecting_by');
        });
    }
};
