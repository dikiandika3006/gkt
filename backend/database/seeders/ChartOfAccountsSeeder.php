<?php

namespace Database\Seeders;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Services\TransferService;
use Illuminate\Database\Seeder;

/**
 * Bagan akun awal, mengikuti Group/Global di sheet "Data Mutasi".
 * Nama kategori = kolom Group (dipakai juga oleh importer untuk mencocokkan).
 */
class ChartOfAccountsSeeder extends Seeder
{
    private const I = CategoryType::Income;

    private const E = CategoryType::Expense;

    private const B = CategoryType::Both;

    public function run(): void
    {
        $op = CategoryNature::Operational;

        $rows = [
            // [kode, nama, kelompok (Global), arah, sifat]
            ['4-100', 'Perpuluhan', 'Perpuluhan', self::I, $op],
            ['4-200', 'Persembahan Syukur', 'Persembahan Syukur', self::I, $op],
            ['4-300', 'Persembahan Natal', 'Persembahan Natal', self::B, $op],
            ['4-310', 'Persembahan Paskah', 'Persembahan Paskah', self::B, $op],
            ['4-900', 'Pendapatan Bunga', 'Pendapatan Bunga', self::I, $op],

            ['5-100', 'Tunjangan Hamba Tuhan', 'Tunjangan', self::E, $op],
            ['5-110', 'Tunjangan Pekerja Gereja', 'Tunjangan', self::B, $op],

            ['5-200', 'Biaya Operasional Gereja', 'Pengeluaran', self::E, $op],
            ['5-201', 'Alat Tulis Kantor (ATK)', 'Pengeluaran', self::E, $op],
            ['5-202', 'Fotokopi & Cetak', 'Pengeluaran', self::E, $op],
            ['5-203', 'Biaya Administrasi Bank', 'Pengeluaran', self::E, $op],
            ['5-204', 'Biaya Telp & Telekomunikasi', 'Pengeluaran', self::E, $op],
            ['5-205', 'Biaya Listrik', 'Pengeluaran', self::E, $op],
            ['5-206', 'PDAM', 'Pengeluaran', self::E, $op],
            ['5-207', 'Biaya BBM & Tol', 'Pengeluaran', self::E, $op],
            ['5-210', 'Biaya Konsumsi', 'Pengeluaran', self::E, $op],
            ['5-211', 'Biaya Rapat & Konsumsi', 'Pengeluaran', self::E, $op],
            ['5-220', 'Perlengkapan Gereja', 'Pengeluaran', self::E, $op],
            ['5-221', 'Biaya Perawatan Gereja', 'Pengeluaran', self::E, $op],
            ['5-222', 'Perawatan Gedung & Kendaraan', 'Pengeluaran', self::B, $op],
            ['5-230', 'Viatikum', 'Pengeluaran', self::E, $op],
            ['5-231', 'Biaya Berobat', 'Pengeluaran', self::E, $op],
            ['5-232', 'Biaya Diakonia', 'Pengeluaran', self::B, $op],
            ['5-233', 'Souvenir Babtis / Sakramen / Hadiah', 'Pengeluaran', self::E, $op],
            ['5-240', 'Support Diakonia', 'Pengeluaran', self::E, $op],
            ['5-241', 'Support PI Misi', 'Pengeluaran', self::E, $op],
            ['5-250', 'Persembahan Keluar', 'Pengeluaran', self::E, $op],
            ['5-251', 'Persepuluhan ke Sinode', 'Pengeluaran', self::E, $op],
            ['5-252', 'Iuran Bulanan Gereja', 'Pengeluaran', self::E, $op],
            ['5-290', 'Biaya Lain-Lain', 'Pengeluaran', self::E, $op],
            ['5-300', 'Biaya Sinodal', 'Biaya Sinodal', self::E, $op],

            ['6-100', 'Komisi Ibadah', 'Komisi', self::B, $op],
            ['6-110', 'Komisi Anak', 'Komisi', self::B, $op],
            ['6-120', 'Komisi Joshcom', 'Komisi', self::B, $op],
            ['6-130', 'Komisi Wanita', 'Komisi', self::B, $op],
            ['6-140', 'Komisi Pria', 'Komisi', self::B, $op],
            ['6-200', 'Persembahan Diakonia', 'Komisi', self::B, $op],
            ['6-210', 'Persembahan PI Misi', 'Komisi', self::B, $op],
            ['6-300', 'Support Lain-lain', 'Komisi', self::B, $op],

            ['7-100', 'Titipan', 'Titipan', self::B, CategoryNature::Titipan],
            ['7-200', 'Pinjaman Kas Kendaraan', 'Pinjaman', self::B, CategoryNature::Pinjaman],

            [TransferService::CATEGORY_OUT, 'Mutasi Keluar (ke kas lain)', 'Mutasi Antar Kas', self::E, CategoryNature::Transfer],
            [TransferService::CATEGORY_IN, 'Mutasi Masuk (dari kas lain)', 'Mutasi Antar Kas', self::I, CategoryNature::Transfer],
        ];

        foreach ($rows as [$code, $name, $group, $type, $nature]) {
            Category::updateOrCreate(['code' => $code], compact('name', 'group', 'type', 'nature'));
        }
    }
}
