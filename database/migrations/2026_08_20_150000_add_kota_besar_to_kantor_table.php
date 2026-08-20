<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which Cabang sit in a big city (Padang, Pekanbaru, Batam — 46 of 112).
 *
 * Ring Area means a different distance depending on this flag: a big-city
 * Ring 1 is 0-1 km while everywhere else it reaches 5 km. The rings were
 * stored as bare labels ("Ring 1".."Ring 3") with nothing recording what
 * distance they stand for, so the same label meant two different things and
 * the screen gave no way to tell which.
 *
 * Defaults to false: Non Kota Besar is the larger group (66), and a Cabang
 * created later is far more likely to be a small town than one of the three
 * big cities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kantor', function (Blueprint $table) {
            $table->boolean('kota_besar')->default(false)->after('cabang_cluster');
        });
    }

    public function down(): void
    {
        Schema::table('kantor', function (Blueprint $table) {
            $table->dropColumn('kota_besar');
        });
    }
};
