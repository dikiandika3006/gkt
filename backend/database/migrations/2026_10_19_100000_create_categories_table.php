<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('group', 100)->index(); // kelompok laporan (Global), mis. Perpuluhan, Tunjangan, Pengeluaran
            $table->string('type', 10)->index(); // income | expense | both
            $table->string('nature', 20)->default('operational')->index(); // operational | titipan | pinjaman | transfer
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
