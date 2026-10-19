<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anggaran tahunan per kategori (RAPB). Realisasi dihitung dari transaksi, tidak disimpan.
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            // Arah anggaran: penting untuk kategori "dua arah".
            $table->string('type', 10);
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['year', 'category_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
