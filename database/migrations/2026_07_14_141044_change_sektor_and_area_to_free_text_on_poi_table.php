<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sektor/area were MySQL ENUM columns rejecting any value outside the 16
 * sektor / 5 RING options at the DB layer. Product decision (2026-07-14):
 * bulk-imported POI data is internal, and a source file's sektor/area labels
 * don't have to match the curated dropdown list exactly — only Outlet
 * (kantor) stays a hard requirement for import. Neither field is ever
 * compared against a fixed value elsewhere in the app (Dashboard groups
 * sektor by whatever's there; area is only ever filtered to a known
 * RING_LEVELS subset, which already tolerates unrecognized values by simply
 * excluding them from that one breakdown — same behavior as before, just no
 * longer enforced at the DB layer too). status_mitra stays an ENUM —
 * untouched here — since the app hard-compares it in several places (BNI /
 * Non-BNI dashboard split, sales' prospectable-POI query).
 *
 * Uses add-copy-drop-rename via the portable Schema Blueprint (not a raw
 * MySQL-only ALTER ... MODIFY) so this also works against the in-memory
 * SQLite connection the test suite runs on — doctrine/dbal isn't installed,
 * which rules out Schema::table()->change() on an existing column anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poi', function (Blueprint $table) {
            $table->string('sektor_txt', 255)->nullable()->after('sektor');
            $table->string('area_txt', 255)->nullable()->after('area');
        });

        DB::statement('UPDATE poi SET sektor_txt = sektor, area_txt = area');

        // SQLite refuses to drop a column that still has an index on it
        // ("error in index ... after drop column") — drop the indexes first,
        // same as MySQL requires implicitly when the column is dropped.
        Schema::table('poi', function (Blueprint $table) {
            $table->dropIndex(['sektor']);
            $table->dropIndex(['area']);
        });

        Schema::table('poi', function (Blueprint $table) {
            $table->dropColumn(['sektor', 'area']);
        });

        Schema::table('poi', function (Blueprint $table) {
            $table->renameColumn('sektor_txt', 'sektor');
            $table->renameColumn('area_txt', 'area');
        });

        Schema::table('poi', function (Blueprint $table) {
            $table->index('sektor');
            $table->index('area');
        });
    }

    public function down(): void
    {
        Schema::table('poi', function (Blueprint $table) {
            $table->enum('sektor_enum', [
                'Food & Beverage', 'Retail & Shopping', 'Education', 'Business & Office', 'Health Care',
                'Leisure & Recreation', 'Travel & Accomodation', 'Lainnya', 'Warehouse & Logistic', 'Wholesaler',
                'Financial Service', 'Residential Areas', 'Government & Public Service', 'Religious Facilities',
                'Manufacture', 'Shipyard',
            ])->nullable()->after('sektor');
            $table->enum('area_enum', ['RING 1', 'RING 2', 'RING 3', 'RING 4', 'RING 5'])->nullable()->after('area');
        });

        // Any free-text value introduced after up() that isn't one of the
        // original 16/5 options is intentionally left null here rather than
        // failing the rollback — a full revert needs a data cleanup pass,
        // not something this migration can safely guess at.
        DB::statement("UPDATE poi SET sektor_enum = sektor WHERE sektor IN (
            'Food & Beverage','Retail & Shopping','Education','Business & Office','Health Care',
            'Leisure & Recreation','Travel & Accomodation','Lainnya','Warehouse & Logistic','Wholesaler',
            'Financial Service','Residential Areas','Government & Public Service','Religious Facilities',
            'Manufacture','Shipyard'
        )");
        DB::statement("UPDATE poi SET area_enum = area WHERE area IN ('RING 1','RING 2','RING 3','RING 4','RING 5')");

        Schema::table('poi', function (Blueprint $table) {
            $table->dropIndex(['sektor']);
            $table->dropIndex(['area']);
        });

        Schema::table('poi', function (Blueprint $table) {
            $table->dropColumn(['sektor', 'area']);
        });

        Schema::table('poi', function (Blueprint $table) {
            $table->renameColumn('sektor_enum', 'sektor');
            $table->renameColumn('area_enum', 'area');
        });

        Schema::table('poi', function (Blueprint $table) {
            $table->index('sektor');
            $table->index('area');
        });
    }
};
