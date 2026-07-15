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
        Schema::create('poi_reopen_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poi_id')->constrained('poi')->cascadeOnDelete();
            $table->enum('action', ['hapus', 'reopen']);
            $table->text('alasan');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poi_reopen_log');
    }
};
