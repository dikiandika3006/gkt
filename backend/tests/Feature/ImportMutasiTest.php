<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportMutasiTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountsSeeder::class);
        CashAccount::factory()->create(['code' => 'BANK-01', 'opening_balance' => 0]);
        $this->file = tempnam(sys_get_temp_dir(), 'mutasi').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function makeFile(array $rows): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Data Mutasi');
        $sheet->fromArray(['Periode', 'TANGGAL', 'Group', 'Global', 'Kode / Nama', 'KETERANGAN', 'Debit', 'Kredit', 'Saldo', 'Note'], null, 'A1');
        $sheet->fromArray($rows, null, 'A2', true);
        (new Xlsx($book))->save($this->file);
    }

    public function test_imports_rows_and_verifies_closing_balance(): void
    {
        $this->makeFile([
            ['2026-09', 46265, 'Saldo', 'Saldo', null, 'Saldo Awal', null, null, 1_000_000],
            ['2026-09', 46266, 'Perpuluhan', 'Perpuluhan', 'Budi', 'Perpuluhan Budi', 500_000, 0, 1_500_000],
            ['2026-09', 46267, 'Titipan', 'Titipan', 'Videotron', 'Titipan Videotron', 3_000_000, 0, 4_500_000],
            ['2026-09', 46268, 'Kategori Baru', 'Pengeluaran', 'Toko', 'Beli sesuatu', 0, 200_000, 4_300_000],
        ]);

        $this->artisan('gkt:import-mutasi', ['file' => $this->file])->assertSuccessful();

        $this->assertEquals(1_000_000, (float) CashAccount::where('code', 'BANK-01')->value('opening_balance'));
        $this->assertEquals(3, Transaction::count());
        $this->assertEquals('Budi', Transaction::where('amount', 500_000)->value('counterparty'));
        $this->assertEquals('2026-09-01', Transaction::where('amount', 500_000)->first()->date->toDateString());
        $this->assertDatabaseHas('categories', ['name' => 'Kategori Baru', 'group' => 'Pengeluaran', 'type' => 'both']);
        $this->assertEquals('titipan', Category::where('name', 'Titipan')->first()->nature->value);
    }

    public function test_rolls_back_when_closing_balance_differs(): void
    {
        $this->makeFile([
            ['2026-09', 46265, 'Saldo', 'Saldo', null, 'Saldo Awal', null, null, 1_000_000],
            ['2026-09', 46266, 'Perpuluhan', 'Perpuluhan', 'Budi', 'Perpuluhan Budi', 500_000, 0, 9_999_999],
        ]);

        $this->artisan('gkt:import-mutasi', ['file' => $this->file])->assertFailed();
        $this->assertEquals(0, Transaction::count());
    }

    public function test_dry_run_saves_nothing_and_refuses_double_import(): void
    {
        $this->makeFile([
            ['2026-09', 46266, 'Perpuluhan', 'Perpuluhan', 'Budi', 'Perpuluhan Budi', 500_000, 0, 500_000],
        ]);

        $this->artisan('gkt:import-mutasi', ['file' => $this->file, '--dry-run' => true])->assertSuccessful();
        $this->assertEquals(0, Transaction::count());

        $this->artisan('gkt:import-mutasi', ['file' => $this->file])->assertSuccessful();
        $this->artisan('gkt:import-mutasi', ['file' => $this->file])->assertFailed();
        $this->assertEquals(1, Transaction::count());
    }
}
