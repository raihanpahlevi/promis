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
        Schema::table('kantor', function (Blueprint $table) {
            // Free text, no separate master table (2026-07-23 product decision)
            // — a Kantor ("Cabang") belongs to exactly one Cabang-Cluster which
            // belongs to exactly one Area, but that hierarchy is "paten" (fixed,
            // already known ahead of time by the business) rather than something
            // admins add/rename ad hoc, so it doesn't need its own CRUD/id-based
            // entities the way `kantor` itself does. Bulk-set via Kelola Kantor's
            // export/import (KantorExport/KantorImport), same round-trip pattern
            // as kode/nama. A POI never stores its own area/cabang_cluster — both
            // are always read through its Cabang (kantor) relation, so there's
            // exactly one place this mapping can drift.
            $table->string('area')->nullable()->after('nama');
            $table->string('cabang_cluster')->nullable()->after('area');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kantor', function (Blueprint $table) {
            $table->dropColumn(['area', 'cabang_cluster']);
        });
    }
};
