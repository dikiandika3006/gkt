<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akses kas per role di modul Mutasi. Role tanpa baris = boleh semua kas
     * (perilaku lama tetap; kas baru otomatis terlihat). Admin selalu semua kas.
     */
    public function up(): void
    {
        Schema::create('role_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('role', 20);
            $table->foreignId('cash_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role', 'cash_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_cash_accounts');
    }
};
