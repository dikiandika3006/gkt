<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('date');
            $table->string('type', 10); // income | expense
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->string('counterparty')->nullable()->index(); // nama jemaat / penerima / pihak terkait
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('transfer_group')->nullable()->index(); // pasangan mutasi antar kas
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
