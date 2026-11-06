<?php

namespace Tests\Feature;

use App\Enums\CashAccountType;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\CashAccountPocket;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BankImportSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $account;

    private CashAccountPocket $kendaraan;

    private CashAccountPocket $pembangunan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));

        $this->account = CashAccount::factory()->create([
            'code' => '7777', 'type' => CashAccountType::Bank, 'account_number' => '019-0947777', 'has_pockets' => true, 'opening_balance' => 1_000_000,
        ]);
        $this->kendaraan = $this->account->pockets()->create(['name' => 'Kendaraan', 'opening_balance' => 400_000, 'sort_order' => 1]);
        $this->pembangunan = $this->account->pockets()->create(['name' => 'Pembangunan', 'opening_balance' => 600_000, 'sort_order' => 2]);
    }

    private function xlsx(array $rows, string $sheet = 'Mutasi'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle($sheet)->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'mutasi.xlsx', null, null, true);
    }

    /** Rekening koran BCA: judul, Debet = keluar, Kredit = masuk, saldo awal tanpa tanggal, footer total. */
    private function bankStatement(): UploadedFile
    {
        return $this->xlsx([
            ['Mutasi Rekening - GKT Jemaat Sinai Batu'],
            ['No. Rekening: 019-0947777 (BCA)   |   Mata uang: Rp'],
            ['Periode: 01/09/2026 - 06/11/2026'],
            [],
            ['Tanggal', 'Keterangan', 'Cabang', 'Debet', 'Kredit', 'Saldo (hitung)', 'Saldo menurut BCA'],
            [null, 'Saldo Awal (1 Sep 2026)', null, null, null, 1_000_000, null],
            ['01/09/2026', 'BIAYA ADM', '0000', 15_000, null, 985_000, 985_000],
            ['28/09/2026', 'TRSF E-BANKING CR 2809/FTSCY 875000.00 kendaraan tgl 30/9 SETIAWATI', '0011', null, 875_000, 1_860_000, 1_860_000],
            ['30/09/2026', 'BUNGA', '0000', null, 453.15, 1_860_453.15, 1_860_453.15],
            [],
            [null, 'Total Mutasi Debet', null, 15_000],
            [null, 'Saldo Akhir (hitung)', null, null, null, 1_860_453.15],
        ]);
    }

    private function payload(array $preview, array $overrides = []): array
    {
        return [
            'cash_account_id' => $this->account->id,
            'rows' => array_map(fn ($r) => [
                'key' => $r['key'], 'date' => $r['date'], 'type' => $r['type'], 'amount' => $r['amount'],
                'description' => $r['description'], 'pocket_id' => $r['pocket_id'],
                'lines' => array_map(fn ($l) => [...$l, 'category_id' => $l['category_id'] ?? Category::where('name', $r['type'] === 'income' ? 'Persembahan Syukur' : 'Biaya Administrasi Bank')->value('id')], $r['lines']),
            ], $preview['rows']),
            ...$overrides,
        ];
    }

    public function test_bank_statement_is_detected_and_imported_with_pockets(): void
    {
        $preview = $this->postJson('/api/transactions/import/preview', ['file' => $this->bankStatement()])->assertOk()->json();

        $this->assertSame($this->account->id, $preview['cash_account_id']);
        $this->assertSame('bank', $preview['detection']['direction']);
        $this->assertSame(5, $preview['detection']['header_row']);
        $this->assertSame(6, $preview['detection']['mapping']['balance']);
        $this->assertCount(3, $preview['rows']);
        $this->assertSame(['expense', 'income', 'income'], array_column($preview['rows'], 'type'));
        $this->assertEquals(0, $preview['summary']['opening_difference']);
        $this->assertEquals(1_860_453.15, $preview['summary']['bank_closing']);

        // Pocket hanya diisi bila yakin: "kendaraan" di keterangan; BIAYA ADM & BUNGA dibiarkan kosong.
        $this->assertSame([null, $this->kendaraan->id, null], array_column($preview['rows'], 'pocket_id'));

        $this->postJson('/api/transactions/import', $this->payload($preview))
            ->assertUnprocessable()->assertJsonValidationErrors(['rows.0.pocket_id', 'rows.2.pocket_id']);

        $preview['rows'][0]['pocket_id'] = $this->pembangunan->id;
        $preview['rows'][2]['pocket_id'] = $this->pembangunan->id;
        $this->postJson('/api/transactions/import', $this->payload($preview))->assertCreated()->assertJsonPath('count', 3);

        $pockets = collect($this->getJson('/api/cash-accounts')->json('data'))->firstWhere('id', $this->account->id)['pockets'];
        $this->assertEquals([1_275_000, 585_453.15], array_column($pockets, 'current_balance'));

        // Upload ulang → semua baris sudah diimpor.
        $again = $this->postJson('/api/transactions/import/preview', ['file' => $this->bankStatement()])->json('rows');
        $this->assertSame(['imported', 'imported', 'imported'], array_column($again, 'status'));
    }

    public function test_cash_book_with_pocket_column_detects_reverse_direction_and_confirms_negative(): void
    {
        $file = $this->xlsx([
            ['Group Mutasi', 'Periode', 'TANGGAL', 'KETERANGAN', 'Debit', 'Kredit', 'Saldo'],
            ['Kendaraan', '2026-10', '2026-10-01', 'Saldo Awal Kendaraan', 400_000, 0, 400_000],
            ['Pembangunan', '2026-10', '2026-10-01', 'Saldo Awal Pembangunan', 600_000, 0, 1_000_000],
            ['Kendaraan', '2026-10', '2026-10-14', 'Persembahan Ibadah Kendaraan', 100_000, null, 1_100_000],
            ['Pembangunan', '2026-10', '2026-10-20', 'Cicilan Sinode', null, 700_000, 400_000],
        ], 'Data Mutasi');

        // File tanpa no. rekening → rekening dipilih user.
        $preview = $this->postJson('/api/transactions/import/preview', ['file' => $file, 'cash_account_id' => $this->account->id])->assertOk()->json();

        $this->assertSame('book', $preview['detection']['direction']);
        $this->assertEquals(1_000_000, $preview['summary']['bank_opening']);
        $this->assertSame(['income', 'expense'], array_column($preview['rows'], 'type'));
        $this->assertSame([$this->kendaraan->id, $this->pembangunan->id], array_column($preview['rows'], 'pocket_id'));

        // Pembangunan 600rb − 700rb = minus → perlu konfirmasi.
        $this->postJson('/api/transactions/import', $this->payload($preview))
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_negative');
        $this->postJson('/api/transactions/import', $this->payload($preview, ['confirm_negative' => true]))->assertCreated();
    }

    public function test_csv_with_indonesian_numbers_and_manual_mapping(): void
    {
        $csv = "Laporan kas\n\nTgl;Uraian;Nominal;Jenis\n05/10/2026;Persembahan Pembangunan;Rp 1.250.000,50;CR\n06 Okt 2026;Bayar tukang;(250.000);DB\n";
        $file = UploadedFile::fake()->createWithContent('kas.csv', $csv);

        $preview = $this->postJson('/api/transactions/import/preview', ['file' => $file, 'cash_account_id' => $this->account->id])->assertOk()->json();

        $this->assertSame(3, $preview['detection']['header_row']);
        $this->assertSame(['2026-10-05', '2026-10-06'], array_column($preview['rows'], 'date'));
        $this->assertEquals([1_250_000.50, 250_000], array_column($preview['rows'], 'amount'));
        $this->assertSame(['income', 'expense'], array_column($preview['rows'], 'type'));
        $this->assertSame($this->pembangunan->id, $preview['rows'][0]['pocket_id']);

        // Koreksi manual: kolom "Jenis" diabaikan → arah dari tanda nominal.
        $file = UploadedFile::fake()->createWithContent('kas.csv', $csv);
        $manual = $this->postJson('/api/transactions/import/preview', [
            'file' => $file, 'cash_account_id' => $this->account->id, 'header_row' => 3,
            'mapping' => ['date' => 0, 'description' => 1, 'amount' => 2],
        ])->assertOk()->json();
        $this->assertSame(['income', 'expense'], array_column($manual['rows'], 'type'));
        $this->assertNull($manual['detection']['mapping']['direction']);
    }
}
