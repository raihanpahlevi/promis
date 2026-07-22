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
            // Free text, no format enforced (a POI's account/CIF number source
            // data is inconsistent — some are pure numeric norek, some are a
            // CIF, some have both jammed into one cell) — same "free text,
            // never rejects" spirit as `pic`. Stamped from KunjunganController
            // whenever a sales fills this field on any kunjungan, not just
            // Closing (see that controller's store()).
            $table->string('norek_cif')->nullable()->after('pic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poi', function (Blueprint $table) {
            $table->dropColumn('norek_cif');
        });
    }
};
