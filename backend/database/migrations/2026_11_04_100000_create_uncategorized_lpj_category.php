<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kategori sementara untuk nota LPJ yang belum diberi kategori oleh bendahara.
 * Kode di luar pola "D-NNN" sehingga diabaikan pembuat kode kategori otomatis.
 */
return new class extends Migration
{
    private const CODE = 'LPJ-BELUM';

    public function up(): void
    {
        if (DB::table('categories')->where('code', self::CODE)->exists()) {
            return;
        }

        DB::table('categories')->insert([
            'code' => self::CODE,
            'name' => 'Belum Dikategorikan',
            'group' => 'Pengeluaran',
            'type' => 'expense',
            'nature' => 'operational',
            'description' => 'Nota LPJ yang kategorinya belum diisi bendahara.',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('categories')->where('code', self::CODE)->value('id');
        $used = $id && (DB::table('transactions')->where('category_id', $id)->exists()
            || DB::table('fund_report_items')->where('category_id', $id)->exists());

        if ($id && ! $used) {
            DB::table('categories')->where('id', $id)->delete();
        }
    }
};
