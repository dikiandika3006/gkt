<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rekap persembahan mingguan (berita acara hitungan, bukan transaksi kas).
        Schema::create('offering_reports', function (Blueprint $table) {
            $table->id();
            $table->date('service_date')->index(); // tanggal ibadah (Minggu)
            $table->date('period_start');
            $table->date('period_end');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offering_report_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offering_report_id')->constrained()->cascadeOnDelete();
            $table->string('section', 20); // ibadah | syukur | perpuluhan | lainnya | pengeluaran
            $table->string('label')->nullable();
            $table->string('name')->nullable(); // nama / kode jemaat
            $table->decimal('amount', 15, 2);
            $table->foreignId('destination_cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $table->string('destination_note')->nullable(); // tujuan di luar sistem, mis. rekening komisi
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Mutasi masuk yang ditautkan sebagai setoran rekap. Satu mutasi hanya untuk satu rekap.
        Schema::create('offering_report_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offering_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offering_report_transactions');
        Schema::dropIfExists('offering_report_lines');
        Schema::dropIfExists('offering_reports');
    }
};
