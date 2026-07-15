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
        // One row per product offered in a visit (a sales can discuss/offer
        // several products in the same kunjungan — confirmed multi-select
        // against the real v1 form). A proper pivot table rather than v1's
        // comma-joined string column, so per-product reporting (dashboard's
        // "Produk BNI - Closing" grid) stays an exact GROUP BY, no LIKE-based
        // fuzzy matching needed the way v1's dashboard had to do it.
        Schema::create('kunjungan_produk', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kunjungan_id')->constrained('kunjungan')->cascadeOnDelete();
            $table->enum('produk', [
                'Tabungan', 'Giro', 'Deposito', 'KUR', 'Kredit SME', 'BNI Fleksi',
                'BWU', 'BNI Griya', 'Kartu Kredit', 'EDC', 'QRIS', 'BNI Direct',
                'Trade Finance', 'Garansi Bank', 'AGEN46', 'Payroll',
                'BIONS Sekuritas', 'Wondr by BNI',
            ]);
            $table->timestamps();

            $table->unique(['kunjungan_id', 'produk']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kunjungan_produk');
    }
};
