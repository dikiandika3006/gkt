<?php

namespace Tests\Feature;

use App\Enums\CategoryNature;
use App\Enums\TransactionType;
use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExtendedReportTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        Sanctum::actingAs(User::factory()->create());
        $this->cash = CashAccount::factory()->create(['opening_balance' => 1_000_000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function trx(string $date, TransactionType $type, Category $category, int $amount, array $extra = []): Transaction
    {
        return Transaction::factory()->create([
            'date' => $date, 'type' => $type, 'category_id' => $category->id, 'cash_account_id' => $this->cash->id, 'amount' => $amount, ...$extra,
        ]);
    }

    public function test_fund_position_separates_special_funds_deposits_and_loans(): void
    {
        $titipan = Category::factory()->both()->nature(CategoryNature::Titipan)->create();
        $pinjaman = Category::factory()->both()->nature(CategoryNature::Pinjaman)->create();
        $this->trx('2026-10-01', TransactionType::Income, $titipan, 300_000, ['counterparty' => 'Komisi Wanita']);
        $this->trx('2026-10-05', TransactionType::Expense, $titipan, 100_000, ['counterparty' => 'Komisi Wanita']);
        $this->trx('2026-10-07', TransactionType::Income, $pinjaman, 200_000, ['counterparty' => 'Dana Kendaraan']);

        $special = CashAccount::factory()->create(['has_pockets' => true, 'opening_balance' => 500_000]);
        $special->pockets()->create(['name' => 'Natal', 'opening_balance' => 500_000]);

        $this->getJson('/api/reports/fund-position?date=2026-11-15')
            ->assertOk()
            ->assertJsonPath('total', 1_900_000)          // 1.4jt kas umum + 0.5jt dana khusus
            ->assertJsonPath('special_funds', 500_000)
            ->assertJsonPath('deposits', 200_000)
            ->assertJsonPath('loan_debt', 200_000)
            ->assertJsonPath('free_funds', 1_000_000)
            ->assertJsonPath('pockets.0.pockets.0.balance', 500_000);

        $categories = $this->getJson('/api/reports/deposits-loans?start_date=2026-10-03&end_date=2026-10-31')->assertOk()->json('categories');
        $deposit = collect($categories)->firstWhere('category_id', $titipan->id);
        $this->assertEquals(300_000, $deposit['opening']);
        $this->assertEquals(100_000, $deposit['expense']);
        $this->assertEquals(200_000, $deposit['closing']);
        $this->assertSame('Komisi Wanita', $deposit['people'][0]['name']);
        $this->assertCount(1, $deposit['people'][0]['transactions']);

        $this->get('/api/reports/fund-position/export')->assertOk();
        $this->get('/api/reports/deposits-loans/export')->assertOk();
    }

    public function test_budget_report_shows_month_and_year_to_date_realization(): void
    {
        $expense = Category::factory()->expense()->create();
        Budget::create(['year' => 2026, 'category_id' => $expense->id, 'type' => 'expense', 'amount' => 12_000_000]);
        $this->trx('2026-09-10', TransactionType::Expense, $expense, 1_000_000);
        $this->trx('2026-10-10', TransactionType::Expense, $expense, 500_000);
        $this->trx('2026-11-10', TransactionType::Expense, $expense, 700_000); // sesudah bulan laporan

        $this->getJson('/api/reports/budget?month=2026-10')
            ->assertOk()
            ->assertJsonPath('rows.0.budget_to_date', 10_000_000)
            ->assertJsonPath('rows.0.month_actual', 500_000)
            ->assertJsonPath('rows.0.actual', 1_500_000)
            ->assertJsonPath('totals.expense.amount', 12_000_000);

        $this->get('/api/reports/budget/export?month=2026-10')->assertOk();
    }
}
