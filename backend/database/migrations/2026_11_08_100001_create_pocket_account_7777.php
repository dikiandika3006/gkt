<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rekening kedua (dana khusus ber-pocket). Nama sementara "7777" — diubah user lewat menu Kas & Bank.
 * Pocket diisi lewat menu Kas & Bank atau `php artisan gkt:import-pocket`.
 */
return new class extends Migration
{
    private const CODE = '7777';

    public function up(): void
    {
        // Data nyata gereja; DB test tetap bersih.
        if (app()->runningUnitTests() || DB::table('cash_accounts')->where('code', self::CODE)->exists()) {
            return;
        }

        DB::table('cash_accounts')->insert([
            'code' => self::CODE,
            'name' => '7777',
            'type' => 'bank',
            'bank_name' => '-',
            'opening_balance' => 0,
            'has_pockets' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('cash_accounts')->where('code', self::CODE)->value('id');

        if ($id && ! DB::table('transactions')->where('cash_account_id', $id)->exists()) {
            DB::table('cash_accounts')->where('id', $id)->delete();
        }
    }
};
