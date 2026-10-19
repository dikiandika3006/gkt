<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data awal Biaya Rutin dari sheet "Biaya Pasti Bulanan" (Excel keuangan 2025).
 * Nominal mengikuti transfer terbaru (Okt 2026). Dijalankan lewat migrasi karena hosting tanpa SSH.
 * Item dengan nama yang sudah ada / kategori yang belum ada dilewati.
 */
return new class extends Migration
{
    private const THT = 'Tunjangan Hamba Tuhan';

    private const TPG = 'Tunjangan Pekerja Gereja';

    /** [nama, kategori, penerima, info bank, nominal, keterangan] */
    private function items(): array
    {
        return [
            ['Tunjangan HT Lz Darwis D Tasemak', self::THT, 'Lz. Darwis D Tasemak', '0101836389 / ANGELIA XAVERIA SHIRLEY', 7_428_000, null],
            ['Dapen Lz Darwis D Tasemak', self::THT, 'Dapen Lz Darwis', '019-0928888', 691_500, null],
            ['Tunjangan HT Lz Daniel Kefin Manafe', self::THT, 'Lz Daniel Kefin Manafe', '316-0168691 / DANIEL KEFIN MANAFE', 3_766_500, null],
            ['Dapen Lz Daniel Kefin Manafe', self::THT, 'Dapen Lz Daniel', '00180-101111', 691_500, null],
            ['Dana Apresiasi Perumahan Lz Daniel', self::THT, 'Lz Daniel Kefin Manafe', null, 1_000_000, 'Belum ditabung'],
            ['Tunjangan HT Lz Dedy Desrany Reppy', self::THT, 'Lz Dedy Desrany Reppy', '316-1332117 / DEDY DESRANY REPPY', 2_200_000, null],
            ['Tunjangan HT Lz Ronius Lase', self::THT, 'Lz Ronius Lase', '125-0790089 / RONIUS LASE', 3_000_000, null],
            ['Tunjangan Pekerja Yesaya Lugu Pratama (+BPJS)', self::TPG, 'Yesaya Lugu Pratama', '019-0836266 / YESAYA LUGU PRATAMA', 2_900_000, 'Tunjangan Rp 2.800.000 + BPJS Rp 100.000 (satu transfer)'],
            ['Tunjangan Pekerja Paulus F Fanggidae', self::TPG, 'Paulus F Fanggidae', '019-5777999 / PAULUS F FANGGIDAE', 2_362_500, null],
            ['Tunjangan Pekerja Johan Joedo', self::TPG, 'Johan Joedo', '0191309366', 1_550_000, null],
            ['Tunjangan CS PT MBS', self::TPG, 'PT. Maju Bersama Santoso', '3687252627', 2_500_000, 'Cleaning service outsource'],
            ['Support Mahasiswa Praktek Lz Falia', self::THT, 'Lz Falia', 'Seabank', 1_000_000, null],
        ];
    }

    public function up(): void
    {
        $categories = DB::table('categories')->whereIn('name', [self::THT, self::TPG])->pluck('id', 'name');
        $existing = DB::table('recurring_expenses')->pluck('name')->flip();
        $order = (int) DB::table('recurring_expenses')->max('sort_order');
        $now = now();

        foreach ($this->items() as [$name, $category, $counterparty, $bank, $amount, $description]) {
            if ($existing->has($name) || ! $categories->has($category)) {
                continue;
            }

            DB::table('recurring_expenses')->insert([
                'name' => $name,
                'category_id' => $categories[$category],
                'counterparty' => $counterparty,
                'bank_info' => $bank,
                'amount' => $amount,
                'description' => $description,
                'is_active' => true,
                'sort_order' => ++$order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $linked = DB::table('transactions')->whereNotNull('recurring_expense_id')->distinct()->pluck('recurring_expense_id');

        DB::table('recurring_expenses')
            ->whereIn('name', array_column($this->items(), 0))
            ->whereNotIn('id', $linked)
            ->delete();
    }
};
