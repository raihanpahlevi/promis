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
        Schema::table('kunjungan', function (Blueprint $table) {
            // Per-visit record of whatever norek/CIF the sales entered THIS
            // time (shown in riwayat kunjungan) — separate from poi.norek_cif,
            // which is the POI's current/latest stamped value. Free text, same
            // as nominal/catatan: optional, no format enforced.
            $table->string('norek_cif')->nullable()->after('hasil');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kunjungan', function (Blueprint $table) {
            $table->dropColumn('norek_cif');
        });
    }
};
