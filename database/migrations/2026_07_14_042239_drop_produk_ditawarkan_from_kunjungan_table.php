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
        // Superseded by kunjungan_produk (a visit can offer multiple products,
        // confirmed against the real v1 form) — this single-value column can't
        // represent that. Safe to drop outright rather than deprecate/keep
        // unused: this is pre-launch, no real data depends on it yet.
        Schema::table('kunjungan', function (Blueprint $table) {
            $table->dropColumn('produk_ditawarkan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kunjungan', function (Blueprint $table) {
            $table->string('produk_ditawarkan')->nullable();
        });
    }
};
