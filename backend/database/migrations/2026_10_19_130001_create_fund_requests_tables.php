<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengajuan dana operasional (mis. Sekretariat) per bulan.
        Schema::create('fund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete(); // kas penerima, mis. Kas Sekretariat
            $table->string('period', 7)->index(); // YYYY-MM
            $table->date('request_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cash_account_id', 'period']);
        });

        Schema::create('fund_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_request_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        // LPJ (laporan pertanggungjawaban), satu per pengajuan.
        Schema::create('fund_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable(); // diisi saat LPJ dinyatakan lengkap
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fund_report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_report_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->decimal('qty', 10, 2)->default(1);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_report_items');
        Schema::dropIfExists('fund_reports');
        Schema::dropIfExists('fund_request_items');
        Schema::dropIfExists('fund_requests');
    }
};
