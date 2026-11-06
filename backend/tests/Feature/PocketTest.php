<?php

namespace Tests\Feature;

use App\Enums\CashAccountType;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\CashAccountPocket;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PocketTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $main;

    private CashAccount $pocketAccount;

    private CashAccountPocket $natal;

    private CashAccountPocket $pembangunan;

    private Category $income;

    private Category $expense;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));

        $this->main = CashAccount::factory()->create(['code' => 'UTAMA', 'opening_balance' => 1_000_000]);
        $this->pocketAccount = CashAccount::factory()->create(['code' => 'POCKET', 'type' => CashAccountType::Bank, 'has_pockets' => true, 'opening_balance' => 300_000]);
        $this->natal = $this->pocketAccount->pockets()->create(['name' => 'Natal', 'opening_balance' => 100_000]);
        $this->pembangunan = $this->pocketAccount->pockets()->create(['name' => 'Pembangunan', 'opening_balance' => 200_000]);
        $this->income = Category::factory()->create();
        $this->expense = Category::factory()->expense()->create();
    }

    private function trx(array $overrides = []): array
    {
        return [
            'date' => '2026-10-05', 'type' => 'expense', 'category_id' => $this->expense->id,
            'cash_account_id' => $this->pocketAccount->id, 'pocket_id' => $this->natal->id,
            'amount' => 50_000, 'description' => 'Dekor', ...$overrides,
        ];
    }

    public function test_create_account_with_pockets_sums_opening_balance(): void
    {
        $this->postJson('/api/cash-accounts', [
            'code' => 'DANA', 'name' => 'Dana Khusus', 'type' => 'bank', 'bank_name' => 'BCA',
            'has_pockets' => true, 'is_active' => true,
            'pockets' => [['name' => 'Kendaraan', 'opening_balance' => 1_500], ['name' => 'Videotron', 'opening_balance' => 2_500]],
        ])->assertCreated()
            ->assertJsonPath('data.has_pockets', true)
            ->assertJsonPath('data.opening_balance', 4000)
            ->assertJsonCount(2, 'data.pockets');

        $this->postJson('/api/cash-accounts', [
            'code' => 'DANA2', 'name' => 'Tanpa pocket', 'type' => 'bank', 'bank_name' => 'BCA', 'has_pockets' => true, 'pockets' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('pockets');
    }

    public function test_pocket_is_required_and_must_belong_to_account(): void
    {
        $this->postJson('/api/transactions', $this->trx(['pocket_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('pocket_id');

        $other = CashAccount::factory()->create(['has_pockets' => true])->pockets()->create(['name' => 'Lain']);
        $this->postJson('/api/transactions', $this->trx(['pocket_id' => $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('pocket_id');

        $this->postJson('/api/transactions', $this->trx(['cash_account_id' => $this->main->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('pocket_id');

        $this->postJson('/api/transactions', $this->trx())->assertCreated()->assertJsonPath('data.pocket.name', 'Natal');
        $this->postJson('/api/transactions', $this->trx(['cash_account_id' => $this->main->id, 'pocket_id' => null]))->assertCreated();
    }

    public function test_pocket_balance_cannot_go_negative(): void
    {
        $this->postJson('/api/transactions', $this->trx(['amount' => 100_001]))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $id = $this->postJson('/api/transactions', $this->trx(['amount' => 100_000]))->assertCreated()->json('data.id');

        // Edit menaikkan nominal → minus → ditolak.
        $this->putJson("/api/transactions/{$id}", $this->trx(['amount' => 150_000]))->assertUnprocessable();

        // Hapus pemasukan yang membuat saldo minus → ditolak.
        $incomeId = $this->postJson('/api/transactions', $this->trx(['type' => 'income', 'category_id' => $this->income->id, 'amount' => 70_000]))->json('data.id');
        $this->postJson('/api/transactions', $this->trx(['amount' => 70_000]))->assertCreated();
        $this->deleteJson("/api/transactions/{$incomeId}")->assertUnprocessable();
    }

    public function test_transfer_between_pockets_of_same_account(): void
    {
        $base = ['date' => '2026-03-20', 'from_cash_account_id' => $this->pocketAccount->id, 'to_cash_account_id' => $this->pocketAccount->id, 'description' => 'Gabung dana Natal'];

        $this->postJson('/api/transfers', [...$base, 'from_pocket_id' => $this->natal->id, 'to_pocket_id' => $this->natal->id, 'amount' => 1_000])
            ->assertUnprocessable()->assertJsonValidationErrors('to_pocket_id');
        $this->postJson('/api/transfers', [...$base, 'from_pocket_id' => $this->natal->id, 'to_pocket_id' => $this->pembangunan->id, 'amount' => 100_001])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $group = $this->postJson('/api/transfers', [...$base, 'from_pocket_id' => $this->natal->id, 'to_pocket_id' => $this->pembangunan->id, 'amount' => 100_000])
            ->assertCreated()->json('data.transfer_group');

        $pockets = collect($this->getJson('/api/cash-accounts')->json('data'))->firstWhere('id', $this->pocketAccount->id)['pockets'];
        $this->assertEquals([0, 300_000], array_column($pockets, 'current_balance'));

        // Pembangunan habiskan dana → hapus transfer membuat Pembangunan minus → ditolak.
        $this->postJson('/api/transactions', $this->trx(['pocket_id' => $this->pembangunan->id, 'amount' => 300_000]))->assertCreated();
        $this->deleteJson("/api/transfers/{$group}")->assertUnprocessable();

        // Rekening Utama → pocket wajib isi pocket tujuan.
        $this->postJson('/api/transfers', ['date' => '2026-03-21', 'from_cash_account_id' => $this->main->id, 'to_cash_account_id' => $this->pocketAccount->id, 'amount' => 1_000, 'description' => 'Setor'])
            ->assertUnprocessable()->assertJsonValidationErrors('to_pocket_id');
    }

    public function test_used_pocket_cannot_be_removed(): void
    {
        $this->postJson('/api/transactions', $this->trx())->assertCreated();

        $this->putJson("/api/cash-accounts/{$this->pocketAccount->id}", [
            'code' => 'POCKET', 'name' => 'Dana', 'type' => 'bank', 'bank_name' => 'BCA', 'has_pockets' => true, 'is_active' => true,
            'pockets' => [['id' => $this->pembangunan->id, 'name' => 'Pembangunan', 'opening_balance' => 200_000]],
        ])->assertUnprocessable();

        $this->putJson("/api/cash-accounts/{$this->pocketAccount->id}", [
            'code' => 'POCKET', 'name' => 'Dana', 'type' => 'bank', 'bank_name' => 'BCA', 'has_pockets' => false, 'opening_balance' => 0, 'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('has_pockets');
    }

    public function test_all_accounts_report_and_dashboard_exclude_pocket_account(): void
    {
        Transaction::factory()->create(['date' => '2026-10-10', 'type' => TransactionType::Income, 'category_id' => $this->income->id, 'cash_account_id' => $this->main->id, 'amount' => 500_000]);
        $this->postJson('/api/transactions', $this->trx(['type' => 'income', 'category_id' => $this->income->id, 'amount' => 900_000]))->assertCreated();

        $this->getJson('/api/reports/summary?start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk()
            ->assertJsonPath('opening_balance', 1_000_000)
            ->assertJsonPath('income', 500_000)
            ->assertJsonPath('closing_balance', 1_500_000)
            ->assertJsonPath('pockets', []);

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('total_balance', 1_500_000);

        $this->getJson("/api/reports/summary?start_date=2026-10-01&end_date=2026-10-31&cash_account_id={$this->pocketAccount->id}")
            ->assertOk()
            ->assertJsonPath('opening_balance', 300_000)
            ->assertJsonPath('closing_balance', 1_200_000)
            ->assertJsonPath('pockets.0.name', 'Natal')
            ->assertJsonPath('pockets.0.income', 900_000)
            ->assertJsonPath('pockets.0.closing_balance', 1_000_000);

        $this->getJson("/api/reports/cash-flow?start_date=2026-10-01&end_date=2026-10-31&cash_account_id={$this->pocketAccount->id}&pocket_id={$this->pembangunan->id}")
            ->assertOk()
            ->assertJsonPath('opening_balance', 200_000)
            ->assertJsonCount(0, 'rows');
    }

    public function test_import_command_creates_pockets_and_matches_excel_balance(): void
    {
        $account = CashAccount::factory()->create(['code' => '7777', 'type' => CashAccountType::Bank]);

        $sheet = (new Spreadsheet)->getActiveSheet()->setTitle('Data Mutasi');
        $sheet->fromArray([
            ['Group Mutasi', 'Periode', 'TANGGAL', 'KETERANGAN', 'Debit', 'Kredit', 'Saldo'],
            ['Kendaraan', '2025-10', '2025-10-01', 'Saldo Awal Kendaraan', 1000, 0, 1000],
            ['Natal', '2025-10', '2025-10-01', 'Saldo Awal Natal', 0, 0, 1000],
            ['Kendaraan', '2025-10', '2025-10-14', 'Persembahan Ibadah Kendaraan', 500, 0, 1500],
            ['Natal', '2025-12', '2025-12-01', 'Qris Natal', 300, 0, 1800],
            ['Natal', '2025-12', '2025-12-19', 'Pembelian Lilin Natal', 0, 100, 1700],
            ['Kendaraan', '2026-06', '2026-06-30', 'Transfer Dana Kendaraan – Dari kendaraan', 0, 200, 1500],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'pocket').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        $this->artisan('gkt:import-pocket', ['file' => $path])->assertSuccessful();

        $account->refresh();
        $this->assertTrue($account->has_pockets);
        $this->assertEquals(1000, (float) $account->opening_balance);
        $this->assertSame(['Kendaraan', 'Natal'], $account->pockets()->pluck('name')->all());
        $this->assertSame(4, Transaction::where('cash_account_id', $account->id)->whereNotNull('pocket_id')->count());
        $this->assertSame('pinjaman', Transaction::where('amount', 200)->first()->category->nature->value);

        // Import ulang ditolak agar tidak dobel.
        $this->artisan('gkt:import-pocket', ['file' => $path])->expectsOutputToContain('sudah berisi transaksi')->assertFailed();
        @unlink($path);
    }
}
