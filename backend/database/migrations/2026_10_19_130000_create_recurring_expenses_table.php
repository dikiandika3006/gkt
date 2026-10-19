<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anggaran biaya rutin bulanan (bukan transaksi otomatis).
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('counterparty')->nullable();
            $table->string('bank_info')->nullable();
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expenses');
    }
};
