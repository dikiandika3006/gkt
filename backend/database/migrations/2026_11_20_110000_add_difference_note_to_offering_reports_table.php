<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alasan selisih crosscheck (setoran mutasi ≠ rekap). Wajib diisi saat crosscheck disimpan dengan selisih.
        Schema::table('offering_reports', function (Blueprint $table) {
            $table->text('difference_note')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('offering_reports', function (Blueprint $table) {
            $table->dropColumn('difference_note');
        });
    }
};
