<?php

namespace Tests\Feature;

use App\Enums\CashAccountType;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\RecurringExpense;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankImportTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->bank = CashAccount::factory()->create([
            'type' => CashAccountType::Bank, 'account_number' => '019-0123456', 'opening_balance' => 10_000_000,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
    }

    private function preview(): array
    {
        $file = UploadedFile::fake()->createWithContent('mutasi.csv', file_get_contents(base_path('tests/Fixtures/bca-mutasi.csv')));

        return $this->postJson('/api/transactions/import/preview', ['file' => $file])->assertOk()->json();
    }

    private function category(string $name): Category
    {
        return Category::where('name', $name)->firstOrFail();
    }

    /** Ubah baris preview menjadi payload simpan. */
    private function payload(array $rows): array
    {
        return [
            'cash_account_id' => $this->bank->id,
            'rows' => array_map(fn ($r) => [
                'key' => $r['key'], 'date' => $r['date'], 'type' => $r['type'], 'amount' => $r['amount'],
                'description' => $r['description'], 'lines' => $r['lines'],
            ], $rows),
        ];
    }

    public function test_preview_detects_account_suggests_categories_and_reconciles(): void
    {
        $preview = $this->preview();

        $this->assertSame($this->bank->id, $preview['cash_account_id']);
        $this->assertEquals(10_000_000, $preview['summary']['bank_opening']);
        $this->assertEquals(0, $preview['summary']['opening_difference']);

        $rows = collect($preview['rows']);
        $this->assertTrue($rows->every(fn ($r) => $r['status'] === 'new'));
        $this->assertSame($this->category('Perpuluhan')->id, $rows[0]['lines'][0]['category_id']);
        $this->assertSame($this->category('Biaya Administrasi Bank')->id, $rows[1]['lines'][0]['category_id']);
        $this->assertSame($this->category('Tunjangan Hamba Tuhan')->id, $rows[2]['lines'][0]['category_id']);
        $this->assertSame('QRIS', $rows[3]['lines'][0]['counterparty']);
        $this->assertNull($rows[4]['lines'][0]['category_id']);
    }

    public function test_commit_with_split_then_reimport_is_marked_imported(): void
    {
        $rows = $this->preview()['rows'];
        $rows[4]['lines'][0]['category_id'] = $this->category('Viatikum')->id;
        // 500rb dipecah: Perpuluhan 400rb + Komisi Anak 100rb.
        $rows[0]['lines'] = [
            ['category_id' => $this->category('Perpuluhan')->id, 'amount' => 400_000, 'counterparty' => '099/BS'],
            ['category_id' => $this->category('Komisi Anak')->id, 'amount' => 100_000, 'counterparty' => '099/BS'],
        ];

        $this->postJson('/api/transactions/import', $this->payload($rows))->assertCreated()->assertJsonPath('count', 6);

        $split = Transaction::where('bank_ref', $rows[0]['key'])->orderBy('id')->get();
        $this->assertCount(2, $split);
        $this->assertSame('Perpuluhan 099/BS', $split[0]->description);
        $this->assertStringContainsString('BUDI SANTOSO', $split[0]->bank_description);

        // Saldo aplikasi = saldo akhir bank.
        $this->getJson('/api/reports/summary?start_date=2026-10-01&end_date=2026-10-31&cash_account_id='.$this->bank->id)
            ->assertJsonPath('closing_balance', 7_485_222);

        // File yang sama: semua imported, dan pola pecahan disarankan ulang.
        $again = collect($this->preview()['rows']);
        $this->assertTrue($again->every(fn ($r) => $r['status'] === 'imported'));

        // Menyimpan ulang ditolak.
        $this->postJson('/api/transactions/import', $this->payload([$rows[1]]))->assertUnprocessable();
        $this->assertSame(6, Transaction::count());
    }

    public function test_history_suggests_previous_split_for_same_sender(): void
    {
        $rows = $this->preview()['rows'];
        $rows[0]['lines'] = [
            ['category_id' => $this->category('Perpuluhan')->id, 'amount' => 400_000, 'counterparty' => '099/BS'],
            ['category_id' => $this->category('Komisi Anak')->id, 'amount' => 100_000, 'counterparty' => '099/BS'],
        ];
        $this->postJson('/api/transactions/import', $this->payload([$rows[0]]))->assertCreated();

        // Bulan berikutnya: pengirim sama, nominal sama.
        $next = str_replace(['01/10/2026","TRSF', '10,500,000.00'], ['01/11/2026","TRSF', '9,000,000.00'], file_get_contents(base_path('tests/Fixtures/bca-mutasi.csv')));
        $file = UploadedFile::fake()->createWithContent('nov.csv', $next);
        $suggested = $this->postJson('/api/transactions/import/preview', ['file' => $file])->json('rows.0.lines');

        $this->assertCount(2, $suggested);
        $this->assertEquals([400_000, 100_000], array_column($suggested, 'amount'));
        $this->assertSame(['099/BS', '099/BS'], array_column($suggested, 'counterparty'));
    }

    public function test_recurring_expense_is_matched_by_amount_and_name(): void
    {
        $item = RecurringExpense::create([
            'name' => 'Tunjangan HT Lz Andi', 'category_id' => $this->category('Tunjangan Hamba Tuhan')->id,
            'counterparty' => 'Lz Andi Pratama', 'amount' => 2_000_000,
        ]);

        $line = $this->preview()['rows'][2]['lines'][0];

        $this->assertSame($item->id, $line['recurring_expense_id']);
        $this->assertSame('2026-10', $line['recurring_period']);
    }

    public function test_existing_manual_transaction_is_flagged_as_possible_duplicate(): void
    {
        Transaction::factory()->expense()->create([
            'cash_account_id' => $this->bank->id, 'date' => '2026-10-01', 'amount' => 15_000,
        ]);

        $rows = collect($this->preview()['rows']);

        $this->assertSame('possible_duplicate', $rows[1]['status']);
        $this->assertSame('possible_duplicate', $rows[0]['status']); // tanggal ≤ transaksi manual terakhir
        $this->assertSame('new', $rows[2]['status']);
    }

    public function test_split_total_must_match_and_category_must_fit(): void
    {
        $rows = $this->preview()['rows'];

        $rows[0]['lines'] = [['category_id' => $this->category('Perpuluhan')->id, 'amount' => 450_000]];
        $this->postJson('/api/transactions/import', $this->payload([$rows[0]]))
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.lines');

        $rows[0]['lines'] = [['category_id' => $this->category('Biaya Listrik')->id, 'amount' => 500_000]];
        $this->postJson('/api/transactions/import', $this->payload([$rows[0]]))
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.lines.0.category_id');

        $this->assertSame(0, Transaction::count());
    }

    public function test_viewer_cannot_import_but_can_export(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));
        $file = UploadedFile::fake()->createWithContent('mutasi.csv', 'x');

        $this->postJson('/api/transactions/import/preview', ['file' => $file])->assertForbidden();
        $this->postJson('/api/transactions/import', [])->assertForbidden();

        Transaction::factory()->create(['cash_account_id' => $this->bank->id]);
        $this->get('/api/transactions/export?type=income')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_invalid_file_returns_friendly_error(): void
    {
        $file = UploadedFile::fake()->createWithContent('lain.csv', "nama,jumlah\nabc,100\n");

        $this->postJson('/api/transactions/import/preview', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Format file tidak dikenali. Gunakan CSV mutasi rekening dari KlikBCA Bisnis.');
    }
}
