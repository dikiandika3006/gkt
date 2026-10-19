<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Hash baris mutasi bank; semua pecahan dari satu baris bank memakai nilai yang sama.
            $table->string('bank_ref', 64)->nullable()->index()->after('notes');
            $table->string('bank_description')->nullable()->after('bank_ref');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['bank_ref']);
            $table->dropColumn(['bank_ref', 'bank_description']);
        });
    }
};
