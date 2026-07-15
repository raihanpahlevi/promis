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
        // Admin-managed master list of job units/titles (e.g. "BRANCH MANAGER") — same
        // pattern as `kantor`: replaces free-text unit_jabatan on `users` so the future
        // "who hasn't visited, grouped by unit" monitoring feature (deferred for now)
        // has consistent values to group by, rather than v1's hardcoded PHP array.
        Schema::create('unit', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit');
    }
};
