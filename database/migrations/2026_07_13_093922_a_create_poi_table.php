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
        Schema::create('poi', function (Blueprint $table) {
            $table->id();
            $table->string('nama_poi');
            $table->text('alamat');
            $table->enum('sektor', [
                'Food & Beverage',
                'Retail & Shopping',
                'Education',
                'Business & Office',
                'Health Care',
                'Leisure & Recreation',
                'Travel & Accomodation',
                'Lainnya',
                'Warehouse & Logistic',
                'Wholesaler',
                'Financial Service',
                'Residential Areas',
                'Government & Public Service',
                'Religious Facilities',
                'Manufacture',
                'Shipyard',
            ]);
            $table->string('sub_sektor')->nullable();
            $table->enum('area', ['RING 1', 'RING 2', 'RING 3', 'RING 4', 'RING 5'])->nullable();
            $table->foreignId('kantor_id')->constrained('kantor');
            $table->enum('status_mitra', [
                'Bukan Nasabah BNI',
                'Nasabah Non Merchant BNI',
                'Nasabah Merchant BNI',
            ]);
            $table->string('pic')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('geocode_status', ['pending', 'success', 'failed'])->default('pending');
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('area');
            $table->index('status_mitra');
            $table->index('sektor');
            $table->index('status');
            $table->index('geocode_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poi');
    }
};
