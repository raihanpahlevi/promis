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
        Schema::create('kunjungan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poi_id')->constrained('poi')->cascadeOnDelete();
            $table->foreignId('sales_id')->constrained('users')->cascadeOnDelete();
            $table->date('tanggal_kunjungan');
            $table->string('produk_ditawarkan')->nullable();
            // 6-stage sales funnel confirmed from the v1 dashboard source (kunjungan_sales.hasil) —
            // not the 3-value guess from the preview mockup badges.
            $table->enum('hasil', [
                'Belum Bertemu Key Person',
                'Belum Berminat',
                'Berminat',
                'Nego Pricing',
                'Collecting Dokumen',
                'Closing',
            ]);
            $table->decimal('nominal', 15, 2)->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index('tanggal_kunjungan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kunjungan');
    }
};
