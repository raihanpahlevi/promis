<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index for the "POI per kantor" aggregate the Summary reports run
 * on every page load: WHERE kantor_id IN (~112) AND status = 'aktif'
 * GROUP BY kantor_id. Separate single-column indexes on kantor_id and status
 * already existed, but MySQL can only use one — it range-scanned the kantor_id
 * FK index and then looked up ~146,000 rows just to read `status`. With both
 * columns in one index the query is answered from the index alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poi', function (Blueprint $table) {
            $table->index(['kantor_id', 'status'], 'poi_kantor_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('poi', function (Blueprint $table) {
            $table->dropIndex('poi_kantor_id_status_index');
        });
    }
};
