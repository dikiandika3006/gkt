<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tutup buku: transaksi bertanggal di bulan terkunci tidak bisa ditambah/diubah/dihapus.
        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique(); // Y-m
            $table->string('notes', 255)->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Rekonsiliasi: saldo rekening koran / hitung fisik per kas per akhir bulan.
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_account_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // Y-m
            $table->decimal('statement_balance', 15, 2);
            $table->string('notes', 255)->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cash_account_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('period_locks');
    }
};
